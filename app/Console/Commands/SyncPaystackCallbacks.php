<?php

namespace App\Console\Commands;

use App\Services\Billing\PaystackGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncPaystackCallbacks extends Command
{
    protected $signature = 'paystack:sync-callbacks {--url= : Full callback URL; defaults to APP_URL/paystack/callback} {--dry-run : Preview without contacting Paystack}';

    protected $description = 'Synchronise the configured Paystack test-page return URLs without changing prices or charging customers';

    public function handle(PaystackGateway $gateway): int
    {
        try {
            if ($gateway->mode() !== 'test') {
                $this->error('This command only updates the configured test payment pages.');
                return self::FAILURE;
            }
            $url = $this->option('url') ?: rtrim(config('app.url'), '/').'/paystack/callback';
            $parts = parse_url($url);
            if (! $parts || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ($parts['path'] ?? '') !== '/paystack/callback'
                || (! app()->environment('local', 'testing') && ($parts['scheme'] ?? '') !== 'https')) {
                $this->error('Use a complete callback URL ending in /paystack/callback, without credentials, query or fragment. HTTPS is required outside local testing.');
                return self::FAILURE;
            }
            $this->line('Test-page callback: '.$url);
            if ($this->option('dry-run')) return self::SUCCESS;
            $http = Http::baseUrl('https://api.paystack.co')->withToken(config('paystack.secret_key'))
                ->acceptJson()->connectTimeout(10)->timeout(25);
            $pages = [];
            // Validate the complete configured set before updating any page.
            foreach (config('paystack.test_payment_pages') as $slug => $config) {
                $response = $http->get('/page/'.$config['page_id'])->throw();
                $page = $response->json('data');
                if ($response->json('status') !== true || ! is_array($page) || ($page['domain'] ?? '') !== 'test'
                    || (string) ($page['id'] ?? '') !== (string) $config['page_id']
                    || ($page['slug'] ?? '') !== basename($config['url'])) {
                    throw new \RuntimeException('Unexpected hosted payment page.');
                }
                $pages[$slug] = $page;
            }
            foreach ($pages as $slug => $page) {
                if (($page['redirect_url'] ?? '') === $url) {
                    $this->line($slug.': already correct');
                    continue;
                }
                $payload = ['redirect_url' => $url];
                foreach (['name', 'description'] as $field) {
                    if (is_string($page[$field] ?? null)) $payload[$field] = $page[$field];
                }
                $http->put('/page/'.$page['id'], $payload)->throw();
                $verified = $http->get('/page/'.$page['id'])->throw();
                if ($verified->json('status') !== true || $verified->json('data.redirect_url') !== $url) {
                    throw new \RuntimeException('The provider did not retain the callback URL.');
                }
                $this->info($slug.': callback updated');
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Callback synchronisation did not complete. Check the page settings before another payment; no prices or customer charges were changed.');
            return self::FAILURE;
        }
    }
}
