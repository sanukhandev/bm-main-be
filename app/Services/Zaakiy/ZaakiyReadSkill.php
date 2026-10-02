<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiySkillResult;

interface ZaakiyReadSkill
{
    public function supports(IntentFrame $intent): bool;

    public function execute(ZaakiyExecutionContext $context): ZaakiySkillResult;
}
