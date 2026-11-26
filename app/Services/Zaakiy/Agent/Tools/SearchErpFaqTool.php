<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Services\Zaakiy\Skills\FaqSkill;
use App\Support\Branch\BranchContext;

final class SearchErpFaqTool implements ZaakiyAgentTool
{
    public function __construct(private readonly FaqSkill $faq) {}

    public function name(): string
    {
        return 'search_erp_faq';
    }

    public function statusLabel(): string
    {
        return 'Checking Baithul Madeena user guide...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Retrieve verified Baithul Madeena ERP product help and FAQ answers (property codes, cheque lifecycle, void rules, branch security, reports).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'topic' => ['type' => 'STRING', 'description' => 'Optional topic or question keyword.'],
                ],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return [];
    }

    public function isWriteProposal(): bool
    {
        return false;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $raw = $this->faq->run((string) ($args['topic'] ?? 'help'), $user, $branch);

        return new ZaakiyToolOutput(
            modelPayload: $raw['data'] ?? [],
            skillResult: new ZaakiySkillResult(
                intent: 'help.faq',
                subject: 'ERP User Guide',
                navigation: isset($raw['navigation']) ? [['label' => $raw['navigation']['label'], 'route' => $raw['navigation']['url'], 'query' => []]] : [],
                meta: ['result_type' => 'faq', 'branch_scoped' => true],
            ),
        );
    }
}
