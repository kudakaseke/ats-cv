<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBillingMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public int $timeout = 60;

    public function __construct(public int $messageId)
    {
        $this->onConnection(config('performance.background_connection'))->onQueue('billing')->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(): void
    {
        Cache::lock('billing-message:'.$this->messageId, 120)->get(function () {
            $message = DB::table('billing_messages')->find($this->messageId);
            if (! $message || $message->sent_at || $message->attempts >= 5) {
                return;
            }
            $user = User::find($message->user_id);
            if (! $user) {
                return;
            }
            DB::table('billing_messages')->where('id', $message->id)->update(['attempts' => $message->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5 * (2 ** $message->attempts))]);
            try {
                Mail::raw($message->message, fn ($mail) => $mail->to($user->email)->subject($message->subject));
                DB::table('billing_messages')->where('id', $message->id)->update(['sent_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable) {
                // The durable outbox retries separately; email cannot undo a verified payment.
                Log::warning('Billing email delivery unavailable.', ['message_id' => $message->id]);
            }
        });
    }
}
