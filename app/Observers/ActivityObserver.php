<?php

namespace App\Observers;

use App\Models\BillingPayment;
use App\Models\CvAnalysis;
use App\Models\Resume;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Model;

class ActivityObserver
{
    private function fields(Model $model): array
    {
        return match (true) {
            $model instanceof BillingPayment => ['reference', 'provider', 'mode', 'status', 'kind', 'amount_cents', 'currency', 'credits', 'subscription_plan_id', 'subscription_id', 'paid_at', 'recurring_status'],
            $model instanceof Subscription => ['subscription_plan_id', 'status', 'starts_at', 'ends_at', 'funding_period'],
            $model instanceof User => ['email_verified_at'],
            $model instanceof Resume => ['source', 'status'],
            $model instanceof CvAnalysis => ['status'],
            default => [],
        };
    }

    private function write(Model $model, string $action): void
    {
        $fields = $this->fields($model);
        $details = ['after' => $model->only($fields)];
        if ($action === 'updated') {
            $changes = array_intersect_key($model->getChanges(), array_flip($fields));
            if ($model instanceof User) {
                foreach (['name', 'email', 'password'] as $field) {
                    if ($model->wasChanged($field)) {
                        $changes[$field.'_changed'] = true;
                    }
                }
            }
            if (! $changes && ! $model instanceof Resume) {
                return;
            }
            $details = ['before' => array_intersect_key($model->getRawOriginal(), array_flip(array_keys($changes))), 'changes' => $changes];
        }
        app(AuditTrail::class)->record($model instanceof User ? $model->id : $model->user_id,
            strtolower(class_basename($model)).'.'.$action, class_basename($model), $model->getKey(), $details);
    }

    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->write($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        // Deletion events retain the subject ID, without retaining CV or account content.
        app(AuditTrail::class)->record($model instanceof User ? null : $model->user_id,
            strtolower(class_basename($model)).'.deleted', class_basename($model), $model->getKey());
    }
}
