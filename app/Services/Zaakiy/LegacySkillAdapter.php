<?php

namespace App\Services\Zaakiy;

final class LegacySkillAdapter implements ZaakiyReadSkill
{
    public function __construct(private readonly string $module, private readonly ZaakiySkill $skill) {}

    public function supports(IntentFrame $intent): bool
    {
        return in_array($this->module, $intent->modules, true);
    }

    public function execute(ZaakiyExecutionContext $context): SkillEvidence
    {
        $result = $this->skill->run($context->intent->question, $context->user, $context->branch);
        $data = json_decode(json_encode($result['data'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        $recordKeys = ['items', 'expiring_agreements', 'expiring_with_outstanding', 'records'];
        $records = [];
        foreach ($recordKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $records = array_merge($records, $data[$key]);
                unset($data[$key]);
            }
        }
        $limit = max(1, min($context->intent->limit, 25));
        $truncated = count($records) > $limit;

        return new SkillEvidence($this->module, $context->intent->intent, $data, array_slice($records, 0, $limit), [], $truncated, $context->intent->timeRange, $result['navigation'] ?? null);
    }
}
