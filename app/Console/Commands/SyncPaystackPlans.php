<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncPaystackPlans extends Command
{
    protected $signature = 'paystack:sync-plans {--dry-run : Preview without contacting Paystack} {--replace-mismatched : Select a matching plan or create a new one, leaving the old remote plan unchanged}';

    protected $description = 'Create or reuse the Monthly, Pro and Annual ZAR plans without subscribing or charging customers';

    public function handle(): int
    {
        $amounts = ['monthly' => config('paystack.monthly_amount'), 'pro' => config('paystack.pro_amount'), 'annual' => config('paystack.annual_amount')];
        foreach ($amounts as $slug => $amount) {
            if (! preg_match('/^[1-9][0-9]*$/', (string) $amount) || (float) $amount > 2147483647) {
                $this->error("Set a positive integer ZAR-cent amount for {$slug} in config/paystack.php or its environment variable.");

                return self::FAILURE;
            }
        }
        $plans = SubscriptionPlan::whereIn('slug', array_keys($amounts))->get()->keyBy('slug');
        if ($plans->count() !== 3) {
            $this->error('Run the SubscriptionPlanSeeder before syncing plans.');

            return self::FAILURE;
        }
        foreach ($amounts as $slug => $amount) {
            $plan = $plans[$slug];
            $this->line($plan->name.': ZAR '.number_format($amount / 100, 2).' / '.$plan->billing_interval);
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $key = config('paystack.secret_key');
        if (! is_string($key) || ! preg_match('/^sk_(test|live)_/', $key, $match)) {
            $this->error('Set PAYSTACK_SECRET_KEY to a valid test or live secret key in .env.');

            return self::FAILURE;
        }
        $mode = $match[1];
        $http = Http::withToken($key)->acceptJson()->timeout(20);
        try {
            foreach ($amounts as $slug => $amount) {
                $plan = $plans[$slug];
                $payload = ['name' => 'atscvdetector '.$plan->name, 'amount' => (int) $amount, 'interval' => $plan->billing_interval, 'currency' => 'ZAR'];
                if ($plan->paystack_plan_code && $plan->paystack_mode === $mode) {
                    $result = $http->get('https://api.paystack.co/plan/'.$plan->paystack_plan_code)->throw()->json();
                    $remote = $result['data'] ?? [];
                    if ((! ($result['status'] ?? false) || ! $this->matches($remote, $payload)) && ! $this->option('replace-mismatched')) {
                        $this->error('Stored '.$plan->name.' plan differs from the requested price or interval. No existing subscriptions were changed.');

                        return self::FAILURE;
                    }
                    if (! ($result['status'] ?? false) || ! $this->matches($remote, $payload)) {
                        $remote = null;
                    }
                } else {
                    $remote = null;
                }
                if (! $remote) {
                    // Find a matching plan first, including after an interrupted previous run.
                    $remote = null;
                    $page = 1;
                    do {
                        $result = $http->get('https://api.paystack.co/plan', ['perPage' => 100, 'page' => $page])->throw()->json();
                        if (! ($result['status'] ?? false)) {
                            throw new \RuntimeException('Unable to list plans.');
                        }
                        foreach ($result['data'] ?? [] as $candidate) {
                            if (($candidate['name'] ?? '') === $payload['name'] && $this->matches($candidate, $payload)) {
                                $remote = $candidate;
                                break;
                            }
                        }
                        $page++;
                    } while (! $remote && $page <= ($result['meta']['pageCount'] ?? 1));
                    if (! $remote) {
                        // Never automatically retry creation: a timeout may still create a plan.
                        $result = $http->post('https://api.paystack.co/plan', $payload)->throw()->json();
                        $remote = $result['data'] ?? [];
                        if (! ($result['status'] ?? false) || ! $this->matches($remote, $payload)) {
                            throw new \RuntimeException('Unexpected plan response.');
                        }
                    }
                }
                if (empty($remote['plan_code']) || ! is_string($remote['plan_code'])) {
                    throw new \RuntimeException('Missing plan code.');
                }
                $plan->update(['paystack_plan_code' => $remote['plan_code'], 'paystack_mode' => $mode, 'paystack_amount_cents' => (int) $amount]);
                $this->info($plan->name.': '.$remote['plan_code'].' ('.$mode.')');
            }
        } catch (\Exception $exception) {
            // Do not print request/response bodies or authentication credentials.
            $this->error('Paystack sync failed. Check account access and connectivity, then rerun to reuse existing matching plans.');

            return self::FAILURE;
        }
        $this->info('Plans synced. Free remains local. Checkout and customer subscriptions have not been enabled.');

        return self::SUCCESS;
    }

    private function matches(array $remote, array $payload): bool
    {
        return (int) ($remote['amount'] ?? 0) === $payload['amount']
            && ($remote['currency'] ?? '') === 'ZAR'
            && ($remote['interval'] ?? '') === $payload['interval'];
    }
}
