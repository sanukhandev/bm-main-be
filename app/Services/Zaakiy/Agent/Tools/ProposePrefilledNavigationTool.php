<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class ProposePrefilledNavigationTool implements ZaakiyAgentTool
{
    /** @var array<string, array{route: string, label: string}> */
    private const TARGETS = [
        'new_owner_agreement' => ['route' => '/app/owner-agreements/new', 'label' => 'Open New Owner Agreement Form'],
        'new_tenant_agreement' => ['route' => '/app/tenant-agreements/new', 'label' => 'Open New Tenant Agreement Form'],
        'new_customer' => ['route' => '/app/customers/new', 'label' => 'Open New Customer Form'],
        'new_property' => ['route' => '/app/properties/new', 'label' => 'Open New Property Form'],
        'new_work_order' => ['route' => '/app/maintenance/work-orders/new', 'label' => 'Open New Work Order Form'],
        'new_invoice' => ['route' => '/app/billing/invoices/new', 'label' => 'Open New Invoice Form'],
        'new_quotation' => ['route' => '/app/billing/quotations/new', 'label' => 'Open New Quotation Form'],
        'petty_cash' => ['route' => '/app/accounts/petty-cash', 'label' => 'Open Petty Cash Daybook'],
    ];

    public function name(): string
    {
        return 'propose_prefilled_navigation';
    }

    public function statusLabel(): string
    {
        return 'Preparing ERP screen link...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Provide a direct, prefilled navigation button to open a complex ERP creation screen (such as multi-installment owner/tenant agreements, invoices, or quotations). Does not mutate data.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'target' => ['type' => 'STRING', 'enum' => array_keys(self::TARGETS)],
                    'label' => ['type' => 'STRING', 'description' => 'Optional button label override.'],
                    'prefill' => [
                        'type' => 'OBJECT',
                        'description' => 'Optional safe scalar query parameters (e.g. property_id, customer_id, agreement_id) to prefill the screen.',
                    ],
                ],
                'required' => ['target'],
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
        $validator = Validator::make($args, [
            'target' => ['required', 'string', 'in:'.implode(',', array_keys(self::TARGETS))],
            'label' => ['nullable', 'string', 'max:80'],
            'prefill' => ['nullable', 'array', 'max:8'],
        ]);

        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $target = self::TARGETS[(string) $args['target']];
        $query = [];
        foreach ((array) ($args['prefill'] ?? []) as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,40}$/', $key) || ! is_scalar($value)) {
                continue;
            }
            $query[$key] = (string) $value;
        }

        $label = ! empty($args['label']) ? (string) $args['label'] : $target['label'];
        $nav = ['label' => $label, 'route' => $target['route']];
        if ($query !== []) {
            $nav['query'] = $query;
        }

        $skillResult = new ZaakiySkillResult(
            intent: 'navigation.prefill',
            subject: $label,
            navigation: [$nav],
            meta: ['result_type' => 'prefilled_navigation'],
        );

        return new ZaakiyToolOutput(
            modelPayload: ['status' => 'navigation_ready', 'label' => $label, 'route' => $target['route'], 'query' => $query],
            skillResult: $skillResult,
        );
    }
}
