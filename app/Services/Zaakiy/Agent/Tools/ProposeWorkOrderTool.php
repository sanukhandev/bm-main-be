<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProposeWorkOrderTool implements ZaakiyAgentTool
{
    public function __construct(private readonly ZaakiyActionProposalService $proposals) {}

    public function name(): string
    {
        return 'propose_work_order';
    }

    public function statusLabel(): string
    {
        return 'Preparing work order proposal...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Prepare a human-in-the-loop confirmation card to create a new maintenance work order in the active branch. Never mutates records until the user clicks Confirm.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'property_id' => ['type' => 'INTEGER', 'description' => 'Active property ID in the current branch (call lookup_entities first if unknown).'],
                    'vendor_id' => ['type' => 'INTEGER', 'description' => 'Optional active vendor customer ID in the current branch.'],
                    'title' => ['type' => 'STRING', 'description' => 'Concise summary title of the maintenance issue (max 180 chars).'],
                    'description' => ['type' => 'STRING', 'description' => 'Detailed maintenance description.'],
                    'priority' => ['type' => 'STRING', 'enum' => ['low', 'normal', 'high', 'urgent']],
                    'service_charge' => ['type' => 'NUMBER', 'description' => 'Optional estimated service charge in AED (max 2 decimal places).'],
                ],
                'required' => ['property_id', 'title'],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return ['maintenance.manage'];
    }

    public function isWriteProposal(): bool
    {
        return true;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $branchId = $branch->id();
        $input = array_merge(['priority' => 'normal'], $args);

        $validator = Validator::make($input, [
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at'))],
            'vendor_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at')->whereExists(fn ($role) => $role->selectRaw('1')->from('customer_role_assignments')->whereColumn('customer_role_assignments.customer_id', 'customers.id')->where('customer_role_assignments.branch_id', $branchId)->where('customer_role_assignments.role', 'vendor')))],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
            'service_charge' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        if ($thread === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'THREAD_REQUIRED', 'message' => 'An active conversation thread is required to propose actions.'],
                errorCode: 'THREAD_REQUIRED',
            );
        }

        $data = $validator->validated();
        $property = DB::table('properties')->where('id', $data['property_id'])->first(['property_code', 'name']);
        $vendorName = ! empty($data['vendor_id'])
            ? (string) DB::table('customers')->where('id', $data['vendor_id'])->value('display_name')
            : 'Unassigned';

        $propertyLabel = trim(($property->property_code ?? '').' — '.($property->name ?? 'Property'));
        $card = $this->proposals->create(
            actionType: 'create_work_order',
            requiredPermission: 'maintenance.manage',
            title: 'Create Maintenance Work Order',
            summary: sprintf('Create %s work order "%s" for %s', $data['priority'], $data['title'], $propertyLabel),
            payload: $data,
            displayFields: [
                ['key' => 'property', 'label' => 'Property', 'value' => $propertyLabel],
                ['key' => 'vendor', 'label' => 'Vendor', 'value' => $vendorName],
                ['key' => 'priority', 'label' => 'Priority', 'value' => ucfirst((string) $data['priority'])],
                ['key' => 'title', 'label' => 'Title', 'value' => (string) $data['title']],
            ],
            editableFields: [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'value' => (string) $data['title']],
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'value' => (string) $data['priority'], 'options' => ['low', 'normal', 'high', 'urgent']],
                ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'value' => (string) ($data['description'] ?? '')],
                ['key' => 'service_charge', 'label' => 'Service Charge (AED)', 'type' => 'number', 'value' => $data['service_charge'] ?? null],
            ],
            riskLevel: 'medium',
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
