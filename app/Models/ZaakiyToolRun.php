<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZaakiyToolRun extends Model
{
    use BelongsToBranch;

    protected $fillable = ['tool_name', 'args_json', 'latency_ms', 'error_code'];

    protected function casts(): array
    {
        return [
            'args_json' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ZaakiyThread::class, 'thread_id');
    }
}
