<?php

namespace App\Services;

use App\Jobs\RecordUserActivity;
use App\Models\AuditLog;
use App\Support\IdentityNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditService
{
    public function record(string $action, ?Model $entity = null, ?array $before = null, ?array $after = null, array $metadata = [], ?int $branchId = null, ?int $actorId = null, ?string $ipAddress = null, ?string $userAgent = null): AuditLog
    {
        $request = app()->bound('request') ? app(Request::class) : null;
        $actorId ??= $request?->user()?->getAuthIdentifier();
        $branchId ??= $entity?->getAttribute('branch_id');
        if ($request?->header('X-Activity-Page') && ! array_key_exists('page', $metadata)) {
            $metadata['page'] = substr((string) (parse_url((string) $request->header('X-Activity-Page'), PHP_URL_PATH) ?: '/'), 0, 255);
        }
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
            'ip_address' => $ipAddress ?? $request?->ip(),
            'user_agent' => $userAgent ?? $request?->userAgent(),
        ]);
    }

    public function recordAsync(string $action, ?int $branchId, ?int $actorId, array $metadata = []): void
    {
        $request = app()->bound('request') ? app(Request::class) : null;

        try {
            RecordUserActivity::dispatch(
                action: $action,
                branchId: $branchId,
                actorId: $actorId,
                metadata: $metadata,
                ipAddress: $request?->ip(),
                userAgent: $request?->userAgent(),
            )->onQueue('activity')->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Unable to queue user activity telemetry.', [
                'action' => $action,
                'branch_id' => $branchId,
                'actor_user_id' => $actorId,
                'exception' => $exception::class,
            ]);
        }
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
            $result[$key] = is_array($item)
                ? $this->clean($item)
                : (in_array($keyString, ['identity_no', 'emirates_id'], true) && is_string($item)
                    ? IdentityNumber::mask($item)
                    : ($keyString === 'page' && is_string($item)
                        ? substr((string) (parse_url($item, PHP_URL_PATH) ?: '/'), 0, 255)
                        : $item));
        }

        return $result;
    }
}
