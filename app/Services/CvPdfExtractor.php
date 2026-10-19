<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class CvPdfExtractor
{
    public function extract(string $path): array
    {
        try {
            $process = new Process([config('cv_exports.python'), base_path('scripts/extract-cv-pdf.py'), $path]);
            $process->setTimeout(20)->mustRun();
            $data = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            if (! is_string($data['text'] ?? null) || trim($data['text']) === '' || mb_strlen($data['text']) > 20000) {
                throw new \RuntimeException('Invalid PDF text.');
            }

            return $data;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'This PDF could not be read. Use a text-based PDF up to 100 pages and 20,000 characters, without a password, or paste the resume text.']);
        }
    }
}
