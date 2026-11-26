<?php

namespace App\Services\Zaakiy\Agent;

use App\Actions\CreatePettyCashEntry;
use App\Actions\CreateWorkOrder;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerRoleAssignment;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\ZaakiyActionProposal;
use App\Models\ZaakiyThread;
use App\Services\AgreementLifecycleService;
use App\Services\AuditService;
use App\Services\DocumentNumberGenerator;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ZaakiyActionProposalService
{
    private const LIFECYCLE_STATUS_MAP = [
        'submit' => 'pending_approval',
        'approve' => 'approved',
        'commence' => 'commenced',
        'hold' => 'on_hold',
        'resume' => 'commenced',
    ];

    public function __construct(
        private readonly CreateWorkOrder $createWorkOrder,
        private readonly CreatePettyCashEntry $createPettyCash,
        private readonly AgreementLifecycleService $agreementLifecycle,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $displayFields
     * @param  array<int, array<string, mixed>>  $editableFields
     * @return array<string, mixed>
     */
    public function create(
        string $actionType,
        string $requiredPermission,
        string $title,
        string $summary,
        array $payload,
        array $displayFields,
        array $editableFields,
        string $riskLevel,
        User $user,
        BranchContext $branch,
        ZaakiyThread $thread,
    ): array {
        $proposal = new ZaakiyActionProposal;
        $proposal->forceFill([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $branch->id(),
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'action_type' => $actionType,
            'summary_text' => Str::limit(trim($summary), 255, ''),
            'payload_json' => $payload,
            'required_permission' => $requiredPermission,
            'idempotency_key' => 'zaakiy-'.Str::uuid(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ])->save();

        $this->audit->record(
            'zaakiy.action_proposed',
            $proposal,
            null,
            ['action_type' => $actionType, 'status' => 'pending'],
            ['source' => 'zaakiy_hitl', 'proposal_id' => $proposal->uuid, 'action_type' => $actionType],
            $branch->id(),
            $user->id,
        );

        return [
            'proposal_id' => $proposal->uuid,
            'action_type' => $actionType,
            'title' => $title,
            'summary' => $proposal->summary_text,
            'branch_name' => $branch->branch()->name,
            'risk_level' => $riskLevel,
            'status' => 'pending',
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'fields' => $displayFields,
            'editable_fields' => $editableFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function confirm(ZaakiyActionProposal $proposal, array $overrides, User $user, BranchContext $branch): array
    {
        $txResult = DB::transaction(function () use ($proposal, $overrides, $user, $branch): array {
            $locked = ZaakiyActionProposal::query()
                ->forBranch($branch->id())
                ->where('uuid', $proposal->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== (int) $user->id) {
                throw new ApiException('FORBIDDEN', 'Only the user who requested this action can confirm it.', 403);
            }

            if ($locked->status !== 'pending') {
                throw new ApiException('PROPOSAL_ALREADY_PROCESSED', 'This action proposal has already been processed.', 409);
            }

            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired'])->save();

                return ['__expired' => true];
            }

            if (! $user->hasPermission($locked->required_permission, $branch->id())) {
                throw new ApiException('FORBIDDEN', 'You no longer have permission to execute this action.', 403);
            }

            $merged = $this->mergeOverrides($locked->action_type, (array) $locked->payload_json, $overrides);

            if (
                $locked->action_type === 'create_customer_draft'
                && in_array('vendor', (array) ($merged['roles'] ?? []), true)
                && ! $user->hasPermission('maintenance.manage', $branch->id())
            ) {
                throw new ApiException('FORBIDDEN', 'You do not have permission to create vendor customers.', 403);
            }

            $validated = $this->validateForExecution($locked->action_type, $merged, $branch->id());
            $executed = $this->executeAction($locked->action_type, $validated, $user, $branch);

            $locked->forceFill([
                'status' => 'executed',
                'confirmed_at' => now(),
                'payload_json' => $validated,
                'result_entity_type' => $executed['entity_type'],
                'result_entity_id' => $executed['entity_id'],
            ])->save();

            $this->audit->record(
                'zaakiy.action_confirmed',
                $locked,
                ['status' => 'pending'],
                [
                    'status' => 'executed',
                    'result_entity_type' => $executed['entity_type'],
                    'result_entity_id' => $executed['entity_id'],
                ],
                [
                    'source' => 'zaakiy_hitl',
                    'proposal_id' => $locked->uuid,
                    'action_type' => $locked->action_type,
                    'idempotency_key' => $locked->idempotency_key,
                ],
                $branch->id(),
                $user->id,
            );

            return [
                'proposal_id' => $locked->uuid,
                'action_type' => $locked->action_type,
                'status' => 'executed',
                'entity_type' => $executed['entity_type'],
                'entity_id' => $executed['entity_id'],
                'label' => $executed['label'],
                'route' => $executed['route'],
            ];
        });

        if (($txResult['__expired'] ?? false) === true) {
            throw new ApiException('PROPOSAL_EXPIRED', 'This action proposal has expired. Ask Zaakiy to prepare a fresh proposal.', 409);
        }

        return $txResult;
    }

    /** @return array<string, mixed> */
    public function reject(ZaakiyActionProposal $proposal, User $user, BranchContext $branch): array
    {
        return DB::transaction(function () use ($proposal, $user, $branch): array {
            $locked = ZaakiyActionProposal::query()
                ->forBranch($branch->id())
                ->where('uuid', $proposal->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== (int) $user->id) {
                throw new ApiException('FORBIDDEN', 'Only the user who requested this action can reject it.', 403);
            }

            if ($locked->status !== 'pending') {
                throw new ApiException('PROPOSAL_ALREADY_PROCESSED', 'This action proposal has already been processed.', 409);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
            ])->save();

            $this->audit->record(
                'zaakiy.action_rejected',
                $locked,
                ['status' => 'pending'],
                ['status' => 'rejected'],
                [
                    'source' => 'zaakiy_hitl',
                    'proposal_id' => $locked->uuid,
                    'action_type' => $locked->action_type,
                ],
                $branch->id(),
                $user->id,
            );

            return [
                'proposal_id' => $locked->uuid,
                'action_type' => $locked->action_type,
                'status' => 'rejected',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeOverrides(string $actionType, array $base, array $overrides): array
    {
        $allowedKeys = match ($actionType) {
            'create_work_order' => ['property_id', 'vendor_id', 'title', 'description', 'priority', 'service_charge'],
            'update_work_order_status' => ['status'],
            'create_customer_draft' => ['customer_type', 'display_name', 'legal_name', 'phone', 'email', 'roles', 'notes'],
            'create_petty_cash_entry' => ['transaction_date', 'direction', 'amount', 'particulars', 'category', 'reference', 'remarks'],
            'transition_agreement_lifecycle' => ['reason'],
            default => [],
        };

        $filteredOverrides = array_intersect_key($overrides, array_flip($allowedKeys));

        return array_merge($base, $filteredOverrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateForExecution(string $actionType, array $payload, int $branchId): array
    {
        $rules = match ($actionType) {
            'create_work_order' => [
                'property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at'))],
                'vendor_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('branch_id', $branchId)->where('status', 'active')->whereNull('deleted_at')->whereExists(fn ($role) => $role->selectRaw('1')->from('customer_role_assignments')->whereColumn('customer_role_assignments.customer_id', 'customers.id')->where('customer_role_assignments.branch_id', $branchId)->where('customer_role_assignments.role', 'vendor')))],
                'title' => ['required', 'string', 'max:180'],
                'description' => ['nullable', 'string', 'max:2000'],
                'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
                'service_charge' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            ],
            'update_work_order_status' => [
                'work_order_id' => ['required', 'integer', Rule::exists('work_orders', 'id')->where(fn ($q) => $q->where('branch_id', $branchId))],
                'status' => ['required', 'string', 'in:open,assigned,in_progress,completed'],
            ],
            'create_customer_draft' => [
                'customer_type' => ['required', 'string', 'in:individual,organization'],
                'display_name' => ['required', 'string', 'max:255'],
                'legal_name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],
                'roles' => ['required', 'array', 'min:1'],
                'roles.*' => ['required', 'string', 'in:owner,tenant,vendor', 'distinct'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'create_petty_cash_entry' => [
                'transaction_date' => ['required', 'date_format:Y-m-d'],
                'direction' => ['required', 'string', 'in:inward,outward'],
                'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
                'particulars' => ['required', 'string', 'max:255'],
                'category' => ['nullable', 'string', 'max:100'],
                'reference' => ['nullable', 'string', 'max:255'],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ],
            'transition_agreement_lifecycle' => [
                'agreement_type' => ['required', 'string', 'in:owner,tenant'],
                'agreement_id' => [
                    'required',
                    'integer',
                    Rule::exists(($payload['agreement_type'] ?? '') === 'tenant' ? 'tenant_agreements' : 'owner_agreements', 'id')
                        ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
                ],
                'action' => ['required', 'string', 'in:submit,approve,commence,hold,resume'],
                'reason' => [($payload['action'] ?? '') === 'hold' ? 'required' : 'nullable', 'string', 'max:500'],
            ],
            default => throw new ApiException('UNSUPPORTED_ACTION', 'Unsupported proposal action type.', 422),
        };

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeAction(string $actionType, array $data, User $user, BranchContext $branch): array
    {
        return match ($actionType) {
            'create_work_order' => $this->executeCreateWorkOrder($data, $user, $branch),
            'update_work_order_status' => $this->executeUpdateWorkOrderStatus($data, $user, $branch),
            'create_customer_draft' => $this->executeCreateCustomerDraft($data, $user, $branch),
            'create_petty_cash_entry' => $this->executeCreatePettyCash($data, $user, $branch),
            'transition_agreement_lifecycle' => $this->executeAgreementLifecycle($data, $user, $branch),
            default => throw new ApiException('UNSUPPORTED_ACTION', 'Unsupported proposal action type.', 422),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeCreateWorkOrder(array $data, User $user, BranchContext $branch): array
    {
        $order = $this->createWorkOrder->execute($branch->branch(), $data, $user->id);

        return [
            'entity_type' => 'work_order',
            'entity_id' => $order->id,
            'label' => 'Work Order '.$order->work_order_no,
            'route' => '/app/maintenance/work-orders/'.$order->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeUpdateWorkOrderStatus(array $data, User $user, BranchContext $branch): array
    {
        $order = WorkOrder::query()->forBranch($branch->id())->lockForUpdate()->findOrFail((int) $data['work_order_id']);
        $before = ['status' => $order->status];
        $status = (string) $data['status'];
        $order->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? ($order->completed_at ?: now()) : null,
        ]);

        $this->audit->record(
            'work_order.status_updated',
            $order,
            $before,
            ['status' => $order->status],
            ['source' => 'zaakiy_hitl'],
            $branch->id(),
            $user->id,
        );

        return [
            'entity_type' => 'work_order',
            'entity_id' => $order->id,
            'label' => sprintf('Work Order %s (%s)', $order->work_order_no, $order->status),
            'route' => '/app/maintenance/work-orders/'.$order->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeCreateCustomerDraft(array $data, User $user, BranchContext $branch): array
    {
        $roles = array_values(array_unique($data['roles']));
        unset($data['roles']);

        $phone = trim((string) ($data['phone'] ?? ''));
        $data['phone'] = $phone !== '' ? $phone : null;
        $data['phone_numbers_json'] = $phone !== '' ? [['type' => 'contact', 'number' => $phone]] : null;

        $sequenceType = in_array('owner', $roles, true)
            ? 'OWNER_CUSTOMER'
            : (in_array('vendor', $roles, true) ? 'VENDOR_CUSTOMER' : 'TENANT_CUSTOMER');
        $data['customer_code'] = $this->numbers->next($branch->branch(), $sequenceType, (int) now()->format('Y'));

        $customer = new Customer($data);
        $customer->forceFill([
            'branch_id' => $branch->id(),
            'status' => 'active',
            'identity_verified_at' => null,
        ])->save();

        foreach ($roles as $role) {
            CustomerRoleAssignment::query()->create([
                'branch_id' => $branch->id(),
                'customer_id' => $customer->id,
                'role' => $role,
            ]);
        }

        $this->audit->record(
            'customer.created',
            $customer,
            null,
            [...$customer->only(['customer_code', 'display_name', 'customer_type', 'phone', 'status']), 'roles' => $roles],
            ['source' => 'zaakiy_hitl'],
            $branch->id(),
            $user->id,
        );

        return [
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'label' => sprintf('Customer %s — %s', $customer->customer_code, $customer->display_name),
            'route' => '/app/customers/'.$customer->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeCreatePettyCash(array $data, User $user, BranchContext $branch): array
    {
        $transaction = $this->createPettyCash->execute($branch->branch(), $data, $user->id);

        return [
            'entity_type' => 'account_transaction',
            'entity_id' => $transaction->id,
            'label' => sprintf('Petty Cash %s (AED %s)', $transaction->document_no, $transaction->amount),
            'route' => '/app/accounts/petty-cash',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{entity_type: string, entity_id: int, label: string, route: string}
     */
    private function executeAgreementLifecycle(array $data, User $user, BranchContext $branch): array
    {
        $type = (string) $data['agreement_type'];
        $targetStatus = self::LIFECYCLE_STATUS_MAP[(string) $data['action']]
            ?? throw new ApiException('ACTION_FORBIDDEN', 'This agreement lifecycle action is not permitted via Zaakiy.', 422);

        /** @var OwnerAgreement|TenantAgreement $agreement */
        $agreement = $this->agreementLifecycle->transition(
            $type,
            (int) $data['agreement_id'],
            $branch->id(),
            $targetStatus,
            $data['reason'] ?? null,
            $user->id,
        );

        return [
            'entity_type' => $type.'_agreement',
            'entity_id' => $agreement->id,
            'label' => sprintf('%s -> %s', $agreement->agreement_no, $agreement->status),
            'route' => sprintf('/app/%s-agreements/%d', $type, $agreement->id),
        ];
    }
}
