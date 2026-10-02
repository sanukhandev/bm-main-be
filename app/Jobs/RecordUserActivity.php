<?php

namespace App\Jobs;

use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordUserActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $action,
        public readonly ?int $branchId,
        public readonly ?int $actorId,
        public readonly array $metadata = [],
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}

    public function handle(AuditService $audit): void
    {
        $audit->record(
            action: $this->action,
            metadata: $this->metadata,
            branchId: $this->branchId,
            actorId: $this->actorId,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
        );
    }
}
