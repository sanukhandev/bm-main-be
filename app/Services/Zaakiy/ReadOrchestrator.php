<?php

namespace App\Services\Zaakiy;

use App\Models\User;
use App\Services\Zaakiy\Skills\AccountsSkill;
use App\Services\Zaakiy\Skills\AgreementsSkill;
use App\Services\Zaakiy\Skills\AuditSkill;
use App\Services\Zaakiy\Skills\BillingSkill;
use App\Services\Zaakiy\Skills\CustomersSkill;
use App\Services\Zaakiy\Skills\DashboardSkill;
use App\Services\Zaakiy\Skills\GeneralSkill;
use App\Services\Zaakiy\Skills\IdentitySkill;
use App\Services\Zaakiy\Skills\IntelligentReportSkill;
use App\Services\Zaakiy\Skills\MaintenanceSkill;
use App\Services\Zaakiy\Skills\PropertiesSkill;
use App\Services\Zaakiy\Skills\ReportsSkill;
use App\Support\Branch\BranchContext;

class ReadOrchestrator
{
    public function __construct(
        private readonly IntentAnalyzer $analyzer,
        private readonly ZaakiyContextBuilder $contextBuilder,
        private readonly EntityResolver $entities,
        private readonly IdentitySkill $identity,
        private readonly AccountsSkill $accounts,
        private readonly MaintenanceSkill $maintenance,
        private readonly AgreementsSkill $agreements,
        private readonly PropertiesSkill $properties,
        private readonly CustomersSkill $customers,
        private readonly DashboardSkill $dashboard,
        private readonly ReportsSkill $reports,
        private readonly BillingSkill $billing,
        private readonly AuditSkill $audit,
        private readonly GeneralSkill $general,
        private readonly IntelligentReportSkill $intelligentReport,
    ) {}

    public function build(string $message, array $history, User $user, BranchContext $branch): array
    {
        $intent = $this->analyzer->analyze($message, $history);
        $execution = new ZaakiyExecutionContext($user, $branch, $intent, now()->toIso8601String());
        $skills = [
            'identity' => $this->identity, 'accounts' => $this->accounts, 'maintenance' => $this->maintenance,
            'agreements' => $this->agreements, 'properties' => $this->properties, 'customers' => $this->customers,
            'dashboard' => $this->dashboard, 'reports' => $this->reports, 'billing' => $this->billing,
            'audit' => $this->audit, 'general' => $this->general,
            'intelligent_report' => $this->intelligentReport,
        ];
        $evidence = [];
        foreach ($intent->modules as $module) {
            $skill = $skills[$module] ?? null;
            if ($skill instanceof ZaakiySkill && $skill->matches($message)) {
                $evidence[] = (new LegacySkillAdapter($module, $skill))->execute($execution)->toArray();
            }
        }
        if ($evidence === []) {
            $evidence[] = (new LegacySkillAdapter('general', $this->general))->execute($execution)->toArray();
        }
        $entityEvidence = $this->entities->resolve($message, $execution);
        if ($entityEvidence) {
            $evidence[] = $entityEvidence->toArray();
        }

        return $this->contextBuilder->build($intent, $execution, $evidence);
    }
}
