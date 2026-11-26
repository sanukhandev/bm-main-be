<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\AgreementLifecycleService;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class ProposeAgreementLifecycleTool implements ZaakiyAgentTool
{
    private const ALLOWED_ACTIONS = ['submit', 'approve', 'commence', 'hold', 'resume'];

    public function __construct(
        private readonly ZaakiyActionProposalService $proposals,
        private readonly AgreementLifecycleService $lifecycle,
    ) {}

    public function name(): string
    {
        return 'propose_agreement_lifecycle';
    }

    public function statusLabel(): string
    {
        return 'Preparing agreement lifecycle proposal...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Prepare a human-in-the-loop confirmation card for a safe owner or tenant agreement lifecycle transition (submit, approve, commence, hold, resume). Destructive cancel and terminate actions are strictly forbidden.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'agreement_type' => ['type' => 'STRING', 'enum' => ['owner', 'tenant']],
                    'agreement_id' => ['type' => 'INTEGER', 'description' => 'Agreement ID in the active branch.'],
                    'action' => ['type' => 'STRING', 'enum' => self::ALLOWED_ACTIONS],
                    'reason' => ['type' => 'STRING', 'description' => 'Reason for the transition (required when action is hold).'],
                ],
                'required' => ['agreement_type', 'agreement_id', 'action'],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return ['agreements.manage'];
    }

    public function isWriteProposal(): bool
    {
        return true;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            return new ZaakiyToolOutput(
                modelPayload: [
                    'error_code' => 'ACTION_FORBIDDEN',
                    'message' => 'Destructive or unsupported agreement lifecycle actions (including cancel and terminate) cannot be executed through Zaakiy.',
                ],
                errorCode: 'ACTION_FORBIDDEN',
            );
        }

        $validator = Validator::make($args, [
            'agreement_type' => ['required', 'string', 'in:owner,tenant'],
            'agreement_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_ACTIONS)],
            'reason' => [$action === 'hold' ? 'required' : 'nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $data = $validator->validated();
        $agreement = $data['agreement_type'] === 'owner'
            ? OwnerAgreement::query()->forBranch($branch->id())->find((int) $data['agreement_id'])
            : TenantAgreement::query()->forBranch($branch->id())->find((int) $data['agreement_id']);

        if ($agreement === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'NOT_FOUND', 'message' => 'Agreement was not found in the active branch.'],
                errorCode: 'NOT_FOUND',
            );
        }

        $available = $this->lifecycle->availableActions($data['agreement_type'], $agreement, $branch->id());
        if (! in_array($action, $available, true)) {
            return new ZaakiyToolOutput(
                modelPayload: [
                    'error_code' => 'INVALID_STATUS_TRANSITION',
                    'message' => sprintf('Agreement %s is currently %s and does not support "%s".', $agreement->agreement_no, $agreement->status, $action),
                ],
                errorCode: 'INVALID_STATUS_TRANSITION',
            );
        }

        if ($thread === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'THREAD_REQUIRED', 'message' => 'An active conversation thread is required to propose actions.'],
                errorCode: 'THREAD_REQUIRED',
            );
        }

        $typeLabel = $data['agreement_type'] === 'owner' ? 'Owner Agreement' : 'Tenant Agreement';
        $card = $this->proposals->create(
            actionType: 'transition_agreement_lifecycle',
            requiredPermission: 'agreements.manage',
            title: sprintf('%s %s', ucfirst($action), $typeLabel),
            summary: sprintf('%s %s %s (currently %s)', ucfirst($action), $typeLabel, $agreement->agreement_no, $agreement->status),
            payload: $data,
            displayFields: [
                ['key' => 'agreement', 'label' => $typeLabel, 'value' => (string) $agreement->agreement_no],
                ['key' => 'current_status', 'label' => 'Current Status', 'value' => (string) $agreement->status],
                ['key' => 'action', 'label' => 'Action', 'value' => ucfirst($action)],
            ],
            editableFields: [
                ['key' => 'reason', 'label' => 'Reason / Notes', 'type' => 'textarea', 'value' => (string) ($data['reason'] ?? '')],
            ],
            riskLevel: 'high',
            user: $user,
            branch: $branch,
            thread: $thread,
        );

        return new ZaakiyToolOutput(
            modelPayload: ['status' => 'proposal_created', 'proposal_id' => $card['proposal_id'], 'summary' => $card['summary'], 'requires_user_confirmation' => true],
            actionProposal: $card,
        );
    }
}
