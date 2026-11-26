<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class ProposeWorkOrderStatusTool implements ZaakiyAgentTool
{
    public function __construct(private readonly ZaakiyActionProposalService $proposals) {}

    public function name(): string
    {
        return 'propose_work_order_status';
    }

    public function statusLabel(): string
    {
        return 'Preparing work order status update...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Prepare a human-in-the-loop confirmation card to update the status of an existing branch work order (open, assigned, in_progress, or completed).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'work_order_id' => ['type' => 'INTEGER', 'description' => 'Work order ID in the active branch.'],
                    'status' => ['type' => 'STRING', 'enum' => ['open', 'assigned', 'in_progress', 'completed']],
                ],
                'required' => ['work_order_id', 'status'],
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
        if (strtolower((string) ($args['status'] ?? '')) === 'cancelled') {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'ACTION_FORBIDDEN', 'message' => 'Cancelling work orders is not permitted via Zaakiy.'],
                errorCode: 'ACTION_FORBIDDEN',
            );
        }

        $validator = Validator::make($args, [
            'work_order_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:open,assigned,in_progress,completed'],
        ]);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $order = WorkOrder::query()->forBranch($branch->id())->find((int) $args['work_order_id']);
        if ($order === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'NOT_FOUND', 'message' => 'Work order was not found in the active branch.'],
                errorCode: 'NOT_FOUND',
            );
        }

        if ($thread === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'THREAD_REQUIRED', 'message' => 'An active conversation thread is required to propose actions.'],
                errorCode: 'THREAD_REQUIRED',
            );
        }

        $data = $validator->validated();
        $card = $this->proposals->create(
            actionType: 'update_work_order_status',
            requiredPermission: 'maintenance.manage',
            title: 'Update Work Order Status',
            summary: sprintf('Move Work Order %s (%s) from %s to %s', $order->work_order_no, $order->title, $order->status, $data['status']),
            payload: $data,
            displayFields: [
                ['key' => 'work_order_no', 'label' => 'Work Order', 'value' => $order->work_order_no.' — '.$order->title],
                ['key' => 'current_status', 'label' => 'Current Status', 'value' => $order->status],
                ['key' => 'status', 'label' => 'New Status', 'value' => (string) $data['status']],
            ],
            editableFields: [
                ['key' => 'status', 'label' => 'Target Status', 'type' => 'select', 'value' => (string) $data['status'], 'options' => ['open', 'assigned', 'in_progress', 'completed']],
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
