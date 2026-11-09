<?php

namespace App\Jobs;

use App\Models\BillingPayment;
use App\Services\Billing\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessPaynowNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 180;
    public int $uniqueFor = 900;

    public function __construct(public string $fingerprint)
    {
        $this->onConnection(config('performance.background_connection'))->onQueue('payments')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(PaymentService $service): void
    {
        $notification = DB::table('payment_notifications')->where('fingerprint', $this->fingerprint)->first();
        if (! $notification || $notification->processed_at || ! $notification->hash_valid) {
            return;
        }
        $payment = BillingPayment::whereKey($notification->billing_payment_id)->where('provider', 'paynow')->firstOrFail();
        try {
            if (! $payment->poll_url && ! $payment->merchant_trace) {
                throw new RuntimeException('Paynow verification URL is unavailable.');
            }
            $service->refresh($payment);
            DB::table('payment_notifications')->where('id', $notification->id)
                ->update(['processed_at' => now(), 'error' => null, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            DB::table('payment_notifications')->where('id', $notification->id)
                ->update(['error' => 'Provider verification unavailable', 'updated_at' => now()]);
            throw $exception;
        }
    }
}
