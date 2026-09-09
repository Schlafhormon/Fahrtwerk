<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public function record(Model $record, string $action, array $before = [], ?string $reason = null, bool $critical = false): void
    {
        if (! config('fahrtwerk.audit_log_enabled') && ! $critical) {
            return;
        }
        $exclude = ['password', 'remember_token', 'auth_version'];
        $after = $record->getAttributes();
        if ($action === 'delete') {
            $after = [];
        }
        $fields = array_unique(array_merge(array_keys($before), array_keys($after)));
        $written = false;
        foreach ($fields as $field) {
            if (in_array($field, $exclude) || ($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }
            AuditEvent::create(['actor_id' => auth()->id(), 'record_type' => class_basename($record), 'record_id' => $record->getKey(), 'action' => $action, 'field' => $field, 'old_value' => $before[$field] ?? null, 'new_value' => $after[$field] ?? null, 'reason' => $reason, 'ip_address' => null, 'created_at' => now()]);
            $written = true;
        }
        if (! $written) {
            AuditEvent::create(['actor_id' => auth()->id(), 'record_type' => class_basename($record), 'record_id' => $record->getKey(), 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
        }
    }
}
