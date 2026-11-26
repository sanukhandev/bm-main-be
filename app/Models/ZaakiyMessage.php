<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZaakiyMessage extends Model
{
    use BelongsToBranch;

    protected $fillable = ['content', 'feedback', 'feedback_note'];

    protected function casts(): array
    {
        return [
            'blocks_json' => 'array',
            'navigation_json' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ZaakiyThread::class, 'thread_id');
    }
}
