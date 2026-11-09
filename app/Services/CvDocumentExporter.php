<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CvDocumentExporter
{
    public function create(array $data, string $format): string
    {
        $directory = storage_path('app/private/cv-exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $prefix = $directory.'/'.Str::uuid();
        $input = $prefix.'.html';
        $layout = $prefix.'.json';
        $output = $prefix.($format === 'pdf' ? '.pdf' : '.docx');
        try {
            $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
            $script = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));
            $css = file_get_contents(resource_path('css/cv-paper.css'));
            $html = view('exports.cv', compact('data', 'script', 'css'))->render();
            if (config('cv_exports.driver') === 'remote') {
                app(RemoteDocumentService::class)->render($html, $format, $output);

                return $output;
            }
            if (config('cv_exports.driver') !== 'local') {
                throw new \RuntimeException('Document processing is not configured.');
            }
            file_put_contents($input, $html);
            $process = new Process([
                config('cv_exports.node'), base_path('scripts/export-cv.mjs'), $input, $output, $layout, $format, config('cv_exports.browser'),
            ]);
            if (PHP_OS_FAMILY === 'Windows') {
                // Some PHP dev-server environments omit Windows' mixed-case SystemRoot.
                // Node/OpenSSL needs it to initialise the operating-system random source.
                $systemRoot = getenv('SystemRoot') ?: getenv('SYSTEMROOT') ?: 'C:\\Windows';
                $drive = substr($systemRoot, 0, 2);
                $process->setEnv([
                    'SystemRoot' => $systemRoot, 'SYSTEMROOT' => $systemRoot,
                    'SystemDrive' => getenv('SystemDrive') ?: $drive,
                    'HOMEDRIVE' => getenv('HOMEDRIVE') ?: $drive,
                    'PROGRAMFILES' => getenv('PROGRAMFILES') ?: $drive.'\\Program Files',
                    'PROGRAMFILES(X86)' => getenv('PROGRAMFILES(X86)') ?: $drive.'\\Program Files (x86)',
                    'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(), 'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
                ]);
            }
            $process->setTimeout(60)->mustRun();
            if ($format === 'word') {
                $document = json_decode(file_get_contents($layout), true, 512, JSON_THROW_ON_ERROR);
                app(CvWordWriter::class)->write($document, $output);
            }
            if (! is_file($output) || filesize($output) === 0) {
                throw new \RuntimeException('No export was generated.');
            }

            return $output;
        } catch (\Throwable $exception) {
            if (is_file($output)) {
                unlink($output);
            }
            throw $exception;
        } finally {
            foreach ([$input, $layout, $output.'.zip'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }
}
