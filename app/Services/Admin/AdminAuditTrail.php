<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminAuditTrail
{
    public function record(string $action, ?string $subjectType = null, string|int|null $subjectId = null,
        ?string $description = null, ?array $old = null, ?array $new = null, ?string $email = null): void
    {
        $actor = Auth::guard('admin')->user();
        DB::table('admin_audit_logs')->insert([
            'actor_id' => $actor && DB::table('users')->where('id', $actor->id)->exists() ? $actor->id : null,
            'actor_email_hash' => $email ? hash('sha256', mb_strtolower(trim($email))) : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'description' => $description ? mb_substr($description, 0, 255) : null,
            'old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => $new ? json_encode($new, JSON_THROW_ON_ERROR) : null,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
