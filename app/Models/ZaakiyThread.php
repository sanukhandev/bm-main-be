<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZaakiyThread extends Model
{
    use BelongsToBranch;

    protected $fillable = ['title'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ZaakiyMessage::class, 'thread_id')->orderBy('id');
    }

    public function toolRuns(): HasMany
    {
        return $this->hasMany(ZaakiyToolRun::class, 'thread_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ZaakiyActionProposal::class, 'thread_id');
    }
}
