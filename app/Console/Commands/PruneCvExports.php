<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneCvExports extends Command
{
    protected $signature = 'cv:prune-exports {--dry-run}';

    protected $description = 'Remove generated export working files older than 24 hours';

    public function handle(): int
    {
        $directory = storage_path('app/private/cv-exports');
        $count = 0;
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (! is_file($path) || is_link($path) || filemtime($path) >= now()->subDay()->timestamp
                || ! preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\.(?:html|json|pdf|docx|docx\.zip)$/i', basename($path))) {
                continue;
            }
            if (! $this->option('dry-run') && ! unlink($path)) {
                $this->error('Unable to remove an expired export file.');

                return self::FAILURE;
            }
            $count++;
        }
        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ').$count.' expired export files.');

        return self::SUCCESS;
    }
}
