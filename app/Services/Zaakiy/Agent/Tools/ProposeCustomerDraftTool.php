<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class ProposeCustomerDraftTool implements ZaakiyAgentTool
{
    public function __construct(private readonly ZaakiyActionProposalService $proposals) {}

    public function name(): string
    {
        return 'propose_customer_draft';
    }

    public function statusLabel(): string
    {
        return 'Preparing customer draft proposal...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Prepare a human-in-the-loop confirmation card to create a new unverified customer record (tenant, owner, or vendor) in the active branch. Identity verification stays on the document scan screen.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'customer_type' => ['type' => 'STRING', 'enum' => ['individual', 'organization']],
                    'display_name' => ['type' => 'STRING', 'description' => 'Customer full name or organization name.'],
                    'legal_name' => ['type' => 'STRING', 'description' => 'Optional legal name.'],
                    'phone' => ['type' => 'STRING', 'description' => 'Optional primary contact phone number.'],
                    'email' => ['type' => 'STRING', 'description' => 'Optional email address.'],
                    'roles' => [
                        'type' => 'ARRAY',
                        'items' => ['type' => 'STRING', 'enum' => ['owner', 'tenant', 'vendor']],
                        'description' => 'One or more business roles for the customer (vendor also requires maintenance.manage).',
                    ],
                    'notes' => ['type' => 'STRING', 'description' => 'Optional internal notes.'],
                ],
                'required' => ['customer_type', 'display_name', 'roles'],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return ['customers.manage'];
    }

    public function isWriteProposal(): bool
    {
        return true;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $validator = Validator::make($args, [
            'customer_type' => ['required', 'string', 'in:individual,organization'],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'in:owner,tenant,vendor', 'distinct'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $data = $validator->validated();
        if (in_array('vendor', $data['roles'], true) && ! $user->hasPermission('maintenance.manage', $branch->id())) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'FORBIDDEN', 'message' => 'Creating vendor records requires maintenance.manage permission.'],
                errorCode: 'FORBIDDEN',
            );
        }

        if ($thread === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'THREAD_REQUIRED', 'message' => 'An active conversation thread is required to propose actions.'],
                errorCode: 'THREAD_REQUIRED',
            );
        }

        $rolesLabel = implode(', ', array_map('ucfirst', $data['roles']));
        $card = $this->proposals->create(
            actionType: 'create_customer_draft',
            requiredPermission: 'customers.manage',
            title: 'Create Customer Draft',
            summary: sprintf('Create %s customer "%s" (%s)', $data['customer_type'], $data['display_name'], $rolesLabel),
            payload: $data,
            displayFields: [
                ['key' => 'display_name', 'label' => 'Display Name', 'value' => (string) $data['display_name']],
                ['key' => 'customer_type', 'label' => 'Type', 'value' => ucfirst((string) $data['customer_type'])],
                ['key' => 'roles', 'label' => 'Roles', 'value' => $rolesLabel],
                ['key' => 'phone', 'label' => 'Phone', 'value' => (string) ($data['phone'] ?? '—')],
            ],
            editableFields: [
                ['key' => 'display_name', 'label' => 'Display Name', 'type' => 'text', 'value' => (string) $data['display_name']],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'value' => (string) ($data['phone'] ?? '')],
                ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'value' => (string) ($data['email'] ?? '')],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => (string) ($data['notes'] ?? '')],
            ],
            riskLevel: 'low',
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
