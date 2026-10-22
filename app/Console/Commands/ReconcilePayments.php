<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPayment;
use App\Models\BillingPayment;
use App\Services\Billing\PaymentService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'billing:reconcile {--limit=100}';

    protected $description = 'Verify recent payment statuses with providers and recover missed callbacks';

    public function handle(PaymentService $service): int
    {
        $payments = BillingPayment::where(fn ($query) => $query->whereIn('status', ['pending', 'paid', 'test_paid'])
            ->orWhere(fn ($query) => $query->where('status', 'reversed')->where('recurring', true)))
            ->where(fn ($query) => $query->where('recurring', true)->orWhere('created_at', '>', now()->subDays(400)))
            ->where(fn ($query) => $query->whereNotNull('poll_url')->orWhereNotNull('provider_reference'))
            ->orderBy('updated_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        $failures = 0;
        foreach ($payments as $payment) {
            try {
                if (config('performance.queue_payment_checks')) {
                    RefreshPayment::dispatch($payment->id);
                } else {
                    $service->refresh($payment);
                }
            } catch (\Throwable $exception) {
                $payment->touch();
                $failures++;
            }
        }
        $this->info('Checked '.$payments->count().' payments; '.$failures.' require retry.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
