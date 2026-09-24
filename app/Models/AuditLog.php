<?php

namespace App\Models;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'branch_id', 'user_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'before_json', 'after_json', 'metadata_json', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['before_json' => 'array', 'after_json' => 'array', 'metadata_json' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ApiException('AUDIT_IMMUTABLE', 'Audit records are append-only.', 409));
        static::deleting(fn () => throw new ApiException('AUDIT_IMMUTABLE', 'Audit records cannot be deleted.', 409));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
