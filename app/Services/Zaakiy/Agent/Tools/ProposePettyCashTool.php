<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyActionProposalService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class ProposePettyCashTool implements ZaakiyAgentTool
{
    public function __construct(private readonly ZaakiyActionProposalService $proposals) {}

    public function name(): string
    {
        return 'propose_petty_cash_entry';
    }

    public function statusLabel(): string
    {
        return 'Preparing petty cash proposal...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Prepare a human-in-the-loop confirmation card to post an inward or outward petty cash transaction in the active branch. Requires accounts.post.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'transaction_date' => ['type' => 'STRING', 'description' => 'Transaction date in YYYY-MM-DD format (defaults to today in Asia/Dubai).'],
                    'direction' => ['type' => 'STRING', 'enum' => ['inward', 'outward']],
                    'amount' => ['type' => 'NUMBER', 'description' => 'Amount in AED (positive, max 2 decimal places).'],
                    'particulars' => ['type' => 'STRING', 'description' => 'Clear description of the petty cash expense or replenishment.'],
                    'category' => ['type' => 'STRING', 'description' => 'Optional expense/receipt category.'],
                    'reference' => ['type' => 'STRING', 'description' => 'Optional receipt or voucher reference.'],
                    'remarks' => ['type' => 'STRING', 'description' => 'Optional additional remarks.'],
                ],
                'required' => ['direction', 'amount', 'particulars'],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return ['accounts.post'];
    }

    public function isWriteProposal(): bool
    {
        return true;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $input = array_merge(['transaction_date' => now('Asia/Dubai')->toDateString()], $args);

        $validator = Validator::make($input, [
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', 'string', 'in:inward,outward'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'particulars' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
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
        $formattedAmount = number_format((float) $data['amount'], 2, '.', '');
        $data['amount'] = $formattedAmount;

        $card = $this->proposals->create(
            actionType: 'create_petty_cash_entry',
            requiredPermission: 'accounts.post',
            title: 'Post Petty Cash Entry',
            summary: sprintf('Post %s petty cash of AED %s on %s — %s', $data['direction'], $formattedAmount, $data['transaction_date'], $data['particulars']),
            payload: $data,
            displayFields: [
                ['key' => 'direction', 'label' => 'Direction', 'value' => ucfirst((string) $data['direction'])],
                ['key' => 'amount', 'label' => 'Amount', 'value' => 'AED '.$formattedAmount],
                ['key' => 'transaction_date', 'label' => 'Date', 'value' => (string) $data['transaction_date']],
                ['key' => 'particulars', 'label' => 'Particulars', 'value' => (string) $data['particulars']],
            ],
            editableFields: [
                ['key' => 'transaction_date', 'label' => 'Transaction Date', 'type' => 'date', 'value' => (string) $data['transaction_date']],
                ['key' => 'direction', 'label' => 'Direction', 'type' => 'select', 'value' => (string) $data['direction'], 'options' => ['inward', 'outward']],
                ['key' => 'amount', 'label' => 'Amount (AED)', 'type' => 'number', 'value' => $formattedAmount],
                ['key' => 'particulars', 'label' => 'Particulars', 'type' => 'text', 'value' => (string) $data['particulars']],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'value' => (string) ($data['category'] ?? '')],
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'value' => (string) ($data['reference'] ?? '')],
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
