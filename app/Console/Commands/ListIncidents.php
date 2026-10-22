<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListIncidents extends Command
{
    protected $signature = 'app:errors {--date= : YYYY-MM-DD, defaults to the application date} {--limit=20}';

    protected $description = 'Read recent private, redacted incidents over SSH; no public error viewer';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Use a YYYY-MM-DD date.');

            return self::FAILURE;
        }
        $path = storage_path('logs/incidents-'.$date.'.log');
        if (! is_file($path)) {
            $this->info('No incident log for '.$date.'.');

            return self::SUCCESS;
        }
        $file = fopen($path, 'rb');
        try {
            $size = filesize($path);
            $offset = max(0, $size - 1048576);
            fseek($file, $offset);
            if ($offset > 0) {
                fgets($file); // Ignore a partial line at the bounded read boundary.
            }
            $lines = explode("\n", stream_get_contents($file));
        } finally {
            fclose($file);
        }
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }
            $context = $entry['context'] ?? [];
            $rows[] = [$entry['datetime'] ?? '', $context['reference'] ?? '', $context['type'] ?? '',
                ($context['file'] ?? '').':'.($context['line'] ?? ''), $context['route'] ?? 'background/console'];
            if (count($rows) >= max(1, min(100, (int) $this->option('limit')))) {
                break;
            }
        }
        $this->table(['Time', 'Reference', 'Exception', 'Code location', 'Route'], $rows);

        return self::SUCCESS;
    }
}
