<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\IntelligentReportService;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class IntelligentReportSkill implements ZaakiySkill
{
    public function __construct(private readonly IntelligentReportService $reports) {}

    public function matches(string $message): bool
    {
        return preg_match('/intelligent report|management report|operational profit|profit loss|leakage|management analysis/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        $period = match (true) {
            preg_match('/last\s+3\s+months?/i', $message) === 1 => 'last_3_months',
            preg_match('/last\s+6\s+months?/i', $message) === 1 => 'last_6_months',
            preg_match('/last\s+12\s+months?/i', $message) === 1 => 'last_12_months',
            preg_match('/last\s+month/i', $message) === 1 => 'last_month',
            preg_match('/this\s+year/i', $message) === 1 => 'this_year',
            default => 'this_month',
        };
        $data = $this->reports->build($user, $branch, ['period' => $period])['data'];

        return ['skill' => 'intelligent_report', 'data' => ['period' => $data['period'], 'summary' => $data['summary'], 'findings' => array_slice($data['findings'], 0, 5), 'trends' => $data['trends']], 'navigation' => ['label' => 'Open Intelligent Report', 'url' => '/app/reports/intelligent']];
    }
}
