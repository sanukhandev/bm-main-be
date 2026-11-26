<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Reports\ReportsService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Support\Branch\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class QueryErpReportsTool implements ZaakiyAgentTool
{
    private const FINANCIAL_REPORTS = [
        'tenant_outstanding',
        'owner_payables',
        'inward_receipts',
        'outward_vouchers',
        'daily_cash_movement',
        'petty_cash',
    ];

    private const ALL_REPORTS = [
        'owner_agreements',
        'tenant_agreements',
        'agreement_expiry',
        'tenant_outstanding',
        'owner_payables',
        'inward_receipts',
        'outward_vouchers',
        'daily_cash_movement',
        'petty_cash',
    ];

    public function __construct(private readonly ReportsService $reports) {}

    public function name(): string
    {
        return 'query_erp_reports';
    }

    public function statusLabel(): string
    {
        return 'Querying ERP report data...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Run read-only ERP tabular reports for the active branch (owner_agreements, tenant_agreements, agreement_expiry, tenant_outstanding, owner_payables, inward_receipts, outward_vouchers, daily_cash_movement, petty_cash). Financial reports require accounts.view.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'report' => [
                        'type' => 'STRING',
                        'enum' => self::ALL_REPORTS,
                    ],
                    'date_from' => ['type' => 'STRING', 'description' => 'Optional YYYY-MM-DD start date.'],
                    'date_to' => ['type' => 'STRING', 'description' => 'Optional YYYY-MM-DD end date.'],
                    'status' => ['type' => 'STRING', 'description' => 'Optional status filter (e.g., draft, commenced, overdue, posted).'],
                    'payment_mode' => ['type' => 'STRING', 'enum' => ['cash', 'cheque', 'bank_transfer']],
                    'cheque_status' => ['type' => 'STRING', 'enum' => ['received', 'deposited', 'cleared', 'bounced', 'cancelled']],
                    'customer_id' => ['type' => 'INTEGER', 'description' => 'Optional customer ID filter.'],
                    'search' => ['type' => 'STRING', 'description' => 'Optional search text.'],
                ],
                'required' => ['report'],
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
            'report' => ['required', 'string', 'in:'.implode(',', self::ALL_REPORTS)],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'status' => ['nullable', 'string', 'max:40'],
            'payment_mode' => ['nullable', 'string', 'in:cash,cheque,bank_transfer'],
            'cheque_status' => ['nullable', 'string', 'in:received,deposited,cleared,bounced,cancelled'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $report = (string) $args['report'];
        $canViewAccounts = $user->hasPermission('accounts.view', $branch->id());
        if (in_array($report, self::FINANCIAL_REPORTS, true) && ! $canViewAccounts) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'FORBIDDEN', 'message' => 'This financial report requires accounts.view permission.'],
                errorCode: 'FORBIDDEN',
            );
        }

        $params = array_filter([
            'date_from' => $args['date_from'] ?? null,
            'date_to' => $args['date_to'] ?? null,
            'status' => $args['status'] ?? null,
            'payment_mode' => $args['payment_mode'] ?? null,
            'cheque_status' => $args['cheque_status'] ?? null,
            'customer_id' => $args['customer_id'] ?? null,
            'search' => $args['search'] ?? null,
            'per_page' => 10,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $syntheticRequest = Request::create('/api/v1/reports', 'GET', $params);
        $payload = match ($report) {
            'owner_agreements' => $this->reports->ownerAgreements($syntheticRequest, $branch, $canViewAccounts),
            'tenant_agreements' => $this->reports->tenantAgreements($syntheticRequest, $branch, $canViewAccounts),
            'agreement_expiry' => $this->reports->expiry($syntheticRequest, $branch),
            'tenant_outstanding' => $this->reports->tenantOutstanding($syntheticRequest, $branch),
            'owner_payables' => $this->reports->ownerPayables($syntheticRequest, $branch),
            'inward_receipts' => $this->reports->transactions($syntheticRequest, $branch, 'inward'),
            'outward_vouchers' => $this->reports->transactions($syntheticRequest, $branch, 'outward'),
            'daily_cash_movement' => $this->reports->cashMovement($syntheticRequest, $branch),
            'petty_cash' => $this->reports->pettyCash($syntheticRequest, $branch),
        };

        $rows = array_slice(is_array($payload['data'] ?? null) ? $payload['data'] : collect($payload['data'] ?? [])->all(), 0, 10);
        $summary = array_filter(is_array($payload['summary'] ?? null) ? $payload['summary'] : (is_array($payload['meta'] ?? null) ? $payload['meta'] : []), 'is_scalar');

        $route = '/app/reports/'.str_replace('_', '-', $report);
        $skillResult = new ZaakiySkillResult(
            intent: 'report.'.$report,
            subject: ucwords(str_replace('_', ' ', $report)),
            summaryMetrics: $summary,
            navigation: [['label' => 'Open '.ucwords(str_replace('_', ' ', $report)).' Report', 'route' => $route, 'query' => array_diff_key($params, ['per_page' => true])]],
            meta: ['result_type' => 'erp_report', 'report' => $report, 'branch_scoped' => true],
        );

        return new ZaakiyToolOutput(
            modelPayload: ['report' => $report, 'summary' => $summary, 'rows' => $rows, 'row_count' => count($rows)],
            skillResult: $skillResult,
        );
    }
}
