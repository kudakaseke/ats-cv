<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainAiCredits extends Command
{
    protected $signature = 'credits:maintain';

    protected $description = 'Release monthly credits within paid terms and refund abandoned AI reservations';

    public function handle(AiCreditService $credits): int
    {
        // AI jobs finish or expire within nine minutes, including queue wait.
        // Recover crashes after ten minutes, without racing an active model call.
        DB::table('ai_operations')->where('status', 'reserved')->where('created_at', '<', now()->subMinutes(10))
            ->orderBy('id')->chunkById(100, function ($operations) use ($credits) {
                $users = User::whereIn('id', $operations->pluck('user_id')->unique())->get()->keyBy('id');
                foreach ($operations as $operation) {
                    if ($user = $users->get($operation->user_id)) {
                        $credits->finish($user, $operation->id, null);
                    }
                }
            });
        User::whereHas('subscriptions', fn ($q) => $q->whereIn('status', Subscription::availableStatuses()))->chunkById(100, function ($users) use ($credits) {
            foreach ($users as $user) {
                $credits->wallet($user);
            }
        });
        $this->info('Credit allocations and abandoned reservations checked.');

        return self::SUCCESS;
    }
}
