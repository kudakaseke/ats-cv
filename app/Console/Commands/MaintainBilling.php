<?php

namespace App\Console\Commands;

use App\Jobs\SendBillingMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\BillingRecords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainBilling extends Command
{
    protected $signature = 'billing:maintain';

    protected $description = 'Expire subscriptions, create renewal reminders and deliver billing messages';

    public function handle(BillingRecords $records, AiCreditService $credits): int
    {
        Subscription::where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now()->addDays(7))
            ->chunkById(100, function ($subscriptions) use ($records, $credits) {
                foreach ($subscriptions as $subscription) {
                    DB::transaction(function () use ($subscription, $records, $credits) {
                        $user = User::whereKey($subscription->user_id)->lockForUpdate()->firstOrFail();
                        $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                        if ($current->status !== 'active') {
                            return;
                        }
                        if ($current->ends_at->lte(now())) {
                            $current->update(['status' => 'expired']);
                            $credits->wallet($user);
                            $records->message($user->id, 'subscription:'.$current->id.':expired', 'Subscription period ended',
                                'Your paid period has ended. Your account, documents and payment history are preserved. View your Subscription page to check current access or renew.');
                        } elseif (! $current->cancelled_at && ! $user->subscriptions()->where('status', 'active')->where('starts_at', '>=', $current->ends_at)->exists()) {
                            $records->message($user->id, 'subscription:'.$current->id.':renewal', 'Subscription renewal reminder',
                                'Your subscription ends on '.$current->ends_at->toDateString().'. Renew on your Subscription page to continue. Paynow payments are one-time payments; we will not deduct money automatically.');
                        }
                    });
                }
            });
        DB::table('billing_messages')->whereNull('sent_at')->where('attempts', '<', 5)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(100)->get()->each(fn ($message) => SendBillingMessage::dispatch($message->id));
        $this->info('Subscription periods and billing messages checked.');

        return self::SUCCESS;
    }
}
