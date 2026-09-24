<?php

namespace App\Services\Zaakiy;

interface ZaakiyReadSkill
{
    public function supports(IntentFrame $intent): bool;

    public function execute(ZaakiyExecutionContext $context): SkillEvidence;
}
