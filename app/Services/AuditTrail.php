<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditTrail
{
    public function record(?int $user, string $event, string $subject, string|int $id, array $details = [], ?string $key = null): void
    {
        // Callers supply explicitly selected fields. Never record request bodies or provider payloads.
        $actor = auth()->id();
        if ($actor && ! DB::table('users')->where('id', $actor)->exists()) {
            $actor = null;
        }
        $row = ['user_id' => $user, 'actor_id' => $actor, 'event' => $event,
            'subject_type' => $subject, 'subject_id' => (string) $id,
            'source' => request()->route()?->getName() ?? (app()->runningInConsole() ? 'system' : 'web'),
            'event_key' => $key, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()];
        if ($key) {
            DB::table('audit_events')->upsert([$row], ['event_key'], ['event_key']);
        } else {
            DB::table('audit_events')->insert($row);
        }
    }
}
