<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], ?Actor $actor = null): AuditLog
    {
        $actor ??= Actor::system();

        return AuditLog::create([
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            'changes' => $changes ?: null,
        ]);
    }
}
