<?php

namespace App\Jobs;

use App\Models\BillingPayment;
use App\Services\Billing\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public int $paymentId)
    {
        $this->onConnection(config('performance.background_connection'))->onQueue('payments')->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(PaymentService $service): void
    {
        if ($payment = BillingPayment::whereKey($this->paymentId)->where('provider', 'paynow')->first()) {
            $service->refresh($payment);
        }
    }
}
