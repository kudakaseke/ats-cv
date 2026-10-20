<?php

namespace App\Console\Commands;

use App\Models\BillingPayment;
use App\Services\Billing\PaypalGateway;
use App\Services\Billing\PaypalSubscriptions;
use Illuminate\Console\Command;

class SetupPaypalPlans extends Command
{
    protected $signature = 'paypal:setup-plans {--product= : Reuse an existing PayPal product ID} {--dry-run}';

    protected $description = 'Prepare Monthly, Pro and Annual automatic-renewal plans';

    public function handle(PaypalGateway $gateway, PaypalSubscriptions $subscriptions): int
    {
        if ($this->option('dry-run')) {
            $this->info('PayPal: USD 4.75 monthly; USD 6.25 Pro monthly; USD 47.40 annually; no trial or setup fee.');

            return self::SUCCESS;
        }
        if (! config('billing.paypal.client_id') || ! config('billing.paypal.client_secret') || ! in_array(config('billing.paypal.mode'), ['sandbox', 'live'], true)) {
            $this->error('Set PayPal credentials and PAYPAL_MODE before creating plans.');

            return self::FAILURE;
        }
        try {
            $payment = new BillingPayment(['mode' => config('billing.paypal.mode')]);
            $client = $gateway->client($payment);
            $product = $this->option('product');
            $results = [];
            foreach (['monthly' => [475, 'MONTH'], 'pro' => [625, 'MONTH'], 'annual' => [4740, 'YEAR']] as $slug => [$amount, $unit]) {
                $existing = config('billing.paypal.plans.'.$slug);
                if ($existing) {
                    $subscriptions->validatePlan(new BillingPayment(['mode' => $payment->mode, 'provider_plan_id' => $existing,
                        'amount_cents' => $amount, 'billing_interval' => $slug === 'annual' ? 'annually' : 'monthly']));
                    $results[$slug] = $existing;

                    continue;
                }
                if (! $product) {
                    $product = $client->withHeaders(['PayPal-Request-Id' => 'atscvdetector-product-v1'])
                        ->post('/v1/catalogs/products', ['name' => 'atscvdetector CV tools', 'type' => 'SERVICE', 'category' => 'SOFTWARE'])->throw()->json('id');
                    if (! is_string($product) || $product === '') {
                        throw new \RuntimeException('Missing product ID.');
                    }
                    $this->info('Product: '.$product.' (reuse with --product if interrupted)');
                }
                $id = $client->withHeaders(['PayPal-Request-Id' => 'atscvdetector-'.$slug.'-'.$amount.'-v1'])->post('/v1/billing/plans', [
                    'product_id' => $product, 'name' => 'atscvdetector '.ucfirst($slug), 'status' => 'ACTIVE',
                    'billing_cycles' => [['frequency' => ['interval_unit' => $unit, 'interval_count' => 1], 'tenure_type' => 'REGULAR',
                        'sequence' => 1, 'total_cycles' => 0, 'pricing_scheme' => ['fixed_price' => ['value' => number_format($amount / 100, 2, '.', ''), 'currency_code' => 'USD']]]],
                    'payment_preferences' => ['auto_bill_outstanding' => false, 'payment_failure_threshold' => 1],
                ])->throw()->json('id');
                if (! is_string($id) || ! str_starts_with($id, 'P-')) {
                    throw new \RuntimeException('Missing plan ID.');
                }
                $results[$slug] = $id;
                $this->info('PAYPAL_'.strtoupper($slug).'_PLAN_ID='.$id);
            }
            $this->info('Save the plan IDs in .env for the '.$payment->mode.' environment, then run config:clear. No customers were subscribed.');
        } catch (\Throwable $exception) {
            $this->error('Plan setup failed. Inspect PayPal before retrying; reuse existing IDs to avoid duplicate products or plans.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
