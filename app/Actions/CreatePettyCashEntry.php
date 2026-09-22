<?php

namespace App\Actions;

use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

class CreatePettyCashEntry
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function execute(Branch $branch, array $data, int $userId): AccountTransaction
    {
        return DB::transaction(function () use ($branch, $data, $userId) {
            $transaction = AccountTransaction::query()->create([
                'branch_id' => $branch->id,
                'document_no' => $this->numbers->next($branch, 'PETTY_CASH', (int) date('Y', strtotime($data['transaction_date']))),
                'direction' => $data['direction'],
                'transaction_date' => $data['transaction_date'],
                'payment_mode' => 'cash',
                'amount' => $data['amount'],
                'source_type' => 'petty_cash',
                'remarks' => trim($data['particulars'].(! empty($data['category']) ? ' | '.$data['category'] : '').(! empty($data['reference']) ? ' | '.$data['reference'] : '').(! empty($data['remarks']) ? ' | '.$data['remarks'] : '')),
                'status' => 'posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            DB::table('audit_logs')->insert(['branch_id' => $branch->id, 'user_id' => $userId, 'action' => 'petty_cash_posted', 'entity_type' => 'account_transaction', 'entity_id' => $transaction->id, 'metadata_json' => json_encode(['direction' => $data['direction'], 'amount' => $data['amount']]), 'created_at' => now(), 'updated_at' => now()]);

            return $transaction->load('party');
        });
    }
}
