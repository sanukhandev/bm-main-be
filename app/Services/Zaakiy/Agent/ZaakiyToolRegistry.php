<?php

namespace App\Services\Zaakiy\Agent;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\Tools\AnalyticsReadTool;
use App\Services\Zaakiy\Agent\Tools\Entity360ReadTool;
use App\Services\Zaakiy\Agent\Tools\IntelligentReportTool;
use App\Services\Zaakiy\Agent\Tools\LookupEntitiesTool;
use App\Services\Zaakiy\Agent\Tools\ProposeAgreementLifecycleTool;
use App\Services\Zaakiy\Agent\Tools\ProposeCustomerDraftTool;
use App\Services\Zaakiy\Agent\Tools\ProposePettyCashTool;
use App\Services\Zaakiy\Agent\Tools\ProposePrefilledNavigationTool;
use App\Services\Zaakiy\Agent\Tools\ProposeWorkOrderStatusTool;
use App\Services\Zaakiy\Agent\Tools\ProposeWorkOrderTool;
use App\Services\Zaakiy\Agent\Tools\QueryErpReportsTool;
use App\Services\Zaakiy\Agent\Tools\SearchErpFaqTool;
use App\Services\Zaakiy\SensitiveDataFilter;
use App\Services\Zaakiy\Skills\Agreement360Skill;
use App\Services\Zaakiy\Skills\AgreementRiskSkill;
use App\Services\Zaakiy\Skills\CollectionsHealthSkill;
use App\Services\Zaakiy\Skills\MaintenanceIntelligenceSkill;
use App\Services\Zaakiy\Skills\ManagementBriefingSkill;
use App\Services\Zaakiy\Skills\Owner360Skill;
use App\Services\Zaakiy\Skills\Property360Skill;
use App\Services\Zaakiy\Skills\RenewalIntelligenceSkill;
use App\Services\Zaakiy\Skills\Tenant360Skill;
use App\Services\Zaakiy\Skills\VacancyAnalysisSkill;
use App\Support\Branch\BranchContext;
use App\Support\IdentityNumber;

class ZaakiyToolRegistry
{
    /** @var array<string, ZaakiyAgentTool> */
    private array $tools = [];

    public function __construct(
        private readonly SensitiveDataFilter $filter,
        LookupEntitiesTool $lookupEntities,
        Property360Skill $property360,
        Tenant360Skill $tenant360,
        Owner360Skill $owner360,
        Agreement360Skill $agreement360,
        CollectionsHealthSkill $collectionsHealth,
        VacancyAnalysisSkill $vacancyAnalysis,
        AgreementRiskSkill $agreementRisk,
        RenewalIntelligenceSkill $renewalIntelligence,
        MaintenanceIntelligenceSkill $maintenanceIntelligence,
        ManagementBriefingSkill $managementBriefing,
        IntelligentReportTool $intelligentReport,
        QueryErpReportsTool $queryErpReports,
        SearchErpFaqTool $searchErpFaq,
        ProposeWorkOrderTool $proposeWorkOrder,
        ProposeWorkOrderStatusTool $proposeWorkOrderStatus,
        ProposeCustomerDraftTool $proposeCustomerDraft,
        ProposePettyCashTool $proposePettyCash,
        ProposeAgreementLifecycleTool $proposeAgreementLifecycle,
        ProposePrefilledNavigationTool $proposePrefilledNavigation,
    ) {
        $propertyTypes = ['apartment', 'villa', 'shop', 'office', 'space', 'labor_camp', 'warehouse', 'land'];

        foreach ([
            $lookupEntities,
            new Entity360ReadTool('get_property_360', 'Loading property 360 profile...', 'Get complete branch-scoped property details including owner, current lease, occupancy, and open maintenance work orders.', 'property_360', 'property_id', 'property', $property360),
            new Entity360ReadTool('get_tenant_360', 'Loading tenant 360 profile...', 'Get complete tenant profile including active leases, rented properties, receivables (if accounts.view), and open work orders.', 'tenant_360', 'tenant_id', 'tenant', $tenant360),
            new Entity360ReadTool('get_owner_360', 'Loading owner 360 profile...', 'Get complete owner profile including owned properties, occupancy, active owner agreements, and payables (if accounts.view).', 'owner_360', 'owner_id', 'owner', $owner360),
            new Entity360ReadTool('get_agreement_360', 'Loading agreement 360 details...', 'Get complete details of an owner or tenant agreement including linked properties, schedule status, and financials (if accounts.view).', 'agreement_360', 'agreement_id', 'agreement', $agreement360, true),
            new AnalyticsReadTool('get_collections_health', 'Analyzing tenant collections...', 'Analyze inward rent collections, outstanding/overdue receivables, upcoming installments, and pending/bounced cheques.', 'collections_health', 'collections_summary', $collectionsHealth, ['accounts.view'], ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques']),
            new AnalyticsReadTool('get_vacancy_analysis', 'Analyzing occupancy and vacancy...', 'Analyze property occupancy rate, vacant units, upcoming vacancies, and vacant units with maintenance issues.', 'vacancy_analysis', 'vacancy_analysis', $vacancyAnalysis, [], ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], [
                'property_type' => ['type' => 'STRING', 'enum' => $propertyTypes],
                'owner_id' => ['type' => 'INTEGER'],
                'maintenance_only' => ['type' => 'BOOLEAN'],
            ]),
            new AnalyticsReadTool('get_agreement_risk', 'Checking agreements needing attention...', 'Identify owner/tenant agreements expiring soon, overdue, with bounced cheques, or with owner coverage mismatches.', 'agreement_risk', 'agreement_risk', $agreementRisk, [], ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], [
                'agreement_type' => ['type' => 'STRING', 'enum' => ['owner', 'tenant']],
            ]),
            new AnalyticsReadTool('get_renewal_intelligence', 'Analyzing renewal candidates...', 'List upcoming owner and tenant agreement renewal candidates with financial and coverage readiness.', 'renewal_intelligence', 'renewal_intelligence', $renewalIntelligence, [], ['renewal_intelligence'], [
                'agreement_type' => ['type' => 'STRING', 'enum' => ['owner', 'tenant']],
                'financial_state' => ['type' => 'STRING', 'enum' => ['overdue', 'outstanding', 'no_outstanding']],
                'coverage_issue' => ['type' => 'BOOLEAN'],
            ]),
            new AnalyticsReadTool('get_maintenance_intelligence', 'Analyzing maintenance work orders...', 'Analyze open maintenance backlog, aging work orders, urgent/high-priority tickets, completions, and property issue counts.', 'maintenance_intelligence', 'maintenance_backlog', $maintenanceIntelligence, [], ['maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], [
                'priority' => ['type' => 'STRING', 'enum' => ['low', 'normal', 'high', 'urgent']],
                'property_type' => ['type' => 'STRING', 'enum' => $propertyTypes],
                'owner_id' => ['type' => 'INTEGER'],
                'minimum_age_days' => ['type' => 'INTEGER'],
            ]),
            new AnalyticsReadTool('get_management_briefing', 'Preparing branch management briefing...', 'Build a cross-domain executive summary combining collections, occupancy, agreement risk, renewals, and maintenance.', 'management_briefing', 'management_briefing', $managementBriefing),
            $intelligentReport,
            $queryErpReports,
            $searchErpFaq,
            $proposeWorkOrder,
            $proposeWorkOrderStatus,
            $proposeCustomerDraft,
            $proposePettyCash,
            $proposeAgreementLifecycle,
            $proposePrefilledNavigation,
        ] as $tool) {
            $this->register($tool);
        }
    }

    public function register(ZaakiyAgentTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ZaakiyAgentTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function declarationsFor(User $user, int $branchId): array
    {
        $declarations = [];
        foreach ($this->tools as $tool) {
            if (! $this->isAuthorized($tool, $user, $branchId)) {
                continue;
            }
            $declarations[] = $tool->declaration();
        }

        return $declarations;
    }

    /** @param array<string, mixed> $args */
    public function execute(string $name, array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'UNKNOWN_TOOL', 'message' => 'Requested tool is not registered.'],
                errorCode: 'UNKNOWN_TOOL',
            );
        }

        if (! $this->isAuthorized($tool, $user, $branch->id())) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'FORBIDDEN', 'message' => 'You do not have permission to access this data.'],
                errorCode: 'FORBIDDEN',
            );
        }

        $output = $tool->execute($args, $user, $branch, $thread);

        return new ZaakiyToolOutput(
            modelPayload: $this->scrub($output->modelPayload),
            skillResult: $output->skillResult,
            disambiguation: $output->disambiguation ? $this->scrub($output->disambiguation) : null,
            actionProposal: $output->actionProposal ? $this->scrub($output->actionProposal) : null,
            errorCode: $output->errorCode,
        );
    }

    private function isAuthorized(ZaakiyAgentTool $tool, User $user, int $branchId): bool
    {
        foreach ($tool->requiredPermissions() as $permission) {
            if (! $user->hasPermission($permission, $branchId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        $cleaned = $this->filter->clean($data);

        return is_array($cleaned) ? $this->maskIdentityValues($cleaned) : [];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function maskIdentityValues(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (is_string($item) && in_array($normalized, ['identity_no', 'emirates_id'], true)) {
                $result[$key] = IdentityNumber::mask($item);

                continue;
            }
            $result[$key] = is_array($item) ? $this->maskIdentityValues($item) : $item;
        }

        return $result;
    }
}
