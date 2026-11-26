<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\IntelligentReportService;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class IntelligentReportTool implements ZaakiyAgentTool
{
    public function __construct(private readonly IntelligentReportService $reports) {}

    public function name(): string
    {
        return 'get_intelligent_report';
    }

    public function statusLabel(): string
    {
        return 'Generating intelligent financial report...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Generate the branch operational profitability, cash movement, leakage detection, and trend Intelligent Report (requires accounts.view). Also returns a PDF download link.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'period' => [
                        'type' => 'STRING',
                        'enum' => ['this_month', 'last_month', 'last_3_months', 'last_6_months', 'last_12_months', 'this_year', 'custom'],
                    ],
                    'date_from' => ['type' => 'STRING', 'description' => 'Required when period is custom (YYYY-MM-DD).'],
                    'date_to' => ['type' => 'STRING', 'description' => 'Required when period is custom (YYYY-MM-DD, max 366-day span).'],
                ],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return ['accounts.view'];
    }

    public function isWriteProposal(): bool
    {
        return false;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $validator = Validator::make($args, [
            'period' => ['nullable', 'string', 'in:this_month,last_month,last_3_months,last_6_months,last_12_months,this_year,custom'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ]);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $period = (string) ($args['period'] ?? 'this_month');
        $input = ['period' => $period];
        if ($period === 'custom') {
            $input['date_from'] = $args['date_from'] ?? null;
            $input['date_to'] = $args['date_to'] ?? null;
        }

        $data = $this->reports->build($user, $branch, $input)['data'];
        $summary = array_filter($data['summary'] ?? [], 'is_scalar');
        $query = array_filter(['period' => $period, 'date_from' => $input['date_from'] ?? null, 'date_to' => $input['date_to'] ?? null]);

        $skillResult = new ZaakiySkillResult(
            intent: 'intelligent_report',
            subject: 'Intelligent Report',
            summaryMetrics: $summary,
            navigation: [
                ['label' => 'Open Intelligent Report', 'route' => '/app/reports/intelligent', 'query' => $query],
            ],
            suggestedFollowups: ['Compare collections with last month.', 'Which tenants have overdue payments?'],
            timeRange: ['from' => $data['period']['from'], 'to' => $data['period']['to']],
            meta: ['result_type' => 'intelligent_report', 'branch_scoped' => true, 'financial_included' => true],
        );

        return new ZaakiyToolOutput(
            modelPayload: [
                'period' => $data['period'],
                'summary' => $data['summary'],
                'findings' => array_slice($data['findings'] ?? [], 0, 6),
                'trends' => $data['trends'] ?? [],
                'accounting_note' => $data['accounting_note'] ?? '',
            ],
            skillResult: $skillResult,
        );
    }
}
