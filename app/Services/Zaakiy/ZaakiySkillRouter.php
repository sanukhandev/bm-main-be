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

class ZaakiySkillRouter
{
    public function __construct(private readonly IdentitySkill $identity, private readonly AccountsSkill $accounts, private readonly MaintenanceSkill $maintenance, private readonly AgreementsSkill $agreements, private readonly PropertiesSkill $properties, private readonly CustomersSkill $customers, private readonly DashboardSkill $dashboard, private readonly ReportsSkill $reports, private readonly BillingSkill $billing, private readonly AuditSkill $audit, private readonly GeneralSkill $general, private readonly IntelligentReportSkill $intelligentReport) {}

    public function run(string $message, User $user, BranchContext $branch): array
    {
        foreach ([$this->identity, $this->intelligentReport, $this->audit, $this->billing, $this->accounts, $this->reports, $this->maintenance, $this->agreements, $this->properties, $this->customers, $this->dashboard] as $skill) {
            if ($skill->matches($message)) {
                return $skill->run($message, $user, $branch);
            }
        }

        return $this->general->run($message, $user, $branch);
    }
}
