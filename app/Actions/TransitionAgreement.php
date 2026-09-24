<?php

namespace App\Actions;

use App\Services\AgreementLifecycleService;

class TransitionAgreement
{
    public function execute(string $type, int $id, int $branchId, string $to, ?string $reason, int $userId)
    {
        return app(AgreementLifecycleService::class)->transition($type, $id, $branchId, $to, $reason, $userId);
    }
}
