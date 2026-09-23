<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public function record(string $action, ?Model $entity = null, ?array $before = null, ?array $after = null, array $metadata = [], ?int $branchId = null, ?int $actorId = null): AuditLog
    {
        $request = app()->bound('request') ? app(Request::class) : null;
        $actorId ??= $request?->user()?->getAuthIdentifier();
        $branchId ??= $entity?->getAttribute('branch_id');
        $metadata = $this->clean($metadata);
        $snapshot = fn (?array $value): ?array => $value === null ? null : $this->clean($value);

        return AuditLog::query()->create([
            'branch_id' => $branchId,
            'user_id' => $actorId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'entity_type' => $this->entityType($entity),
            'entity_id' => $entity?->getKey(),
            'before_json' => $snapshot($before),
            'after_json' => $snapshot($after),
            'metadata_json' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function entityType(?Model $entity): ?string
    {
        return $entity ? match (class_basename($entity)) {
            'OwnerAgreement' => 'owner_agreement',
            'TenantAgreement' => 'tenant_agreement',
            'AccountTransaction' => 'account_transaction',
            default => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($entity))),
        } : null;
    }

    private function clean(array $value): array
    {
        $sensitive = ['password', 'password_confirmation', 'remember_token', 'token', 'access_token', 'api_key', 'secret', 'authorization', 'cookie'];
        $result = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            if (collect($sensitive)->contains(fn ($needle) => $keyString === $needle || str_contains($keyString, $needle))) {
                continue;
            }
            $result[$key] = is_array($item) ? $this->clean($item) : $item;
        }

        return $result;
    }
}
