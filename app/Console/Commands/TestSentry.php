<?php

namespace App\Console\Commands;

use App\Exceptions\SentrySetupTest;
use App\Services\IncidentReporter;
use Illuminate\Console\Command;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;

class TestSentry extends Command
{
    protected $signature = 'app:sentry-test';

    protected $description = 'Send one synthetic, sanitised incident to the configured Sentry project';

    public function handle(): int
    {
        if (! config('sentry.dsn')) {
            $this->error('Add the Laravel project DSN to SENTRY_LARAVEL_DSN in the private environment, then clear/rebuild configuration.');

            return self::FAILURE;
        }
        try {
            throw new SentrySetupTest('Synthetic setup error; no customer information.');
        } catch (SentrySetupTest $exception) {
            $id = app(IncidentReporter::class)->report($exception);
        }
        if (! $id || app(HubInterface::class)->getClient()?->flush(5)->getStatus() !== ResultStatus::success()) {
            $this->error('The SDK did not complete the test. Check the DSN, network access and project settings.');

            return self::FAILURE;
        }
        $this->info('Test handed to the Sentry transport. Confirm SentrySetupTest in your project Issues.');
        $this->line('Event ID: '.$id);

        return self::SUCCESS;
    }
}
