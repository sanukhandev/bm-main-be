<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZaakiyActionProposal extends Model
{
    use BelongsToBranch;

    protected $fillable = ['summary_text', 'payload_json'];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ZaakiyThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
