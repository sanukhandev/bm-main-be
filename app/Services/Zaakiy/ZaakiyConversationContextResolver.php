<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use App\Support\Branch\BranchContext;

final class ZaakiyConversationContextResolver
{
    public function resolve(?array $raw, IntentFrame $current, string $message, BranchContext $branch): ZaakiyConversationContext
    {
        $previous = ZaakiyConversationContext::fromArray($raw);
        $branchId = $branch->id();
        $branchChanged = (int) ($previous->branchContext['branch_id'] ?? $branchId) !== $branchId;
        $vacancyIntents = ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'];
        $maintenanceIntents = ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'];
        $ownerVacancyFollowUp = in_array($current->intent, $vacancyIntents, true)
            && $previous->intent === 'owner_360'
            && preg_match('/\b(?:property|properties|vacant|vacancy)\b/i', $message) === 1;
        $ownerMaintenanceFollowUp = in_array($current->intent, $maintenanceIntents, true)
            && $previous->intent === 'owner_360'
            && preg_match('/\b(?:maintenance|work orders?|property|properties)\b/i', $message) === 1;
        $followUp = $this->isFollowUp($message) || $ownerVacancyFollowUp || $ownerMaintenanceFollowUp;
        $explicitDomain = $this->domain($current);
        $topicChanged = ! $ownerVacancyFollowUp && ! $ownerMaintenanceFollowUp && $explicitDomain !== null && $previous->domain !== null && $explicitDomain !== $previous->domain;
        $reset = preg_match('/^(start over|new question|ignore that)\b/i', trim($message)) === 1;
        $inherit = $followUp && ! $topicChanged && ! $reset && $raw !== null && ! $branchChanged;
        $comparisonRequested = $current->comparisonRequested || preg_match('/compare|compared with|compared to|versus|\bvs\b|difference between|change from|same period last year/i', $message) === 1;

        $filters = $inherit ? $previous->filters : [];
        $filters = array_merge($filters, $current->filters);
        if (($previous->filters['trend'] ?? false) && preg_match('/\b(?:daily|weekly|monthly|quarterly|day by day|week by week|month by month)\b/i', $message, $match) === 1) {
            $filters['trend'] = true;
            $filters['trend_granularity'] = match (true) {
                preg_match('/daily|day by day/i', $match[0]) === 1 => 'day',
                preg_match('/weekly|week by week/i', $match[0]) === 1 => 'week',
                preg_match('/monthly|month by month/i', $match[0]) === 1 => 'month',
                default => 'quarter',
            };
        }
        $this->addAmountFilter($filters, $message);
        if (preg_match('/\boverdue\b/i', $message) === 1) {
            $filters['status'] = 'overdue';
        }
        if (in_array($current->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true)
            || in_array($previous->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true)) {
            if (preg_match('/\bno\s+outstanding\b|\bfully\s+paid\b/i', $message) === 1) {
                $filters['financial_state'] = 'no_outstanding';
            } elseif (preg_match('/\boutstanding\b/i', $message) === 1) {
                $filters['financial_state'] = 'outstanding';
            } elseif (preg_match('/\boverdue\b/i', $message) === 1) {
                $filters['financial_state'] = 'overdue';
            }
            if (preg_match('/\bcoverage\b/i', $message) === 1) {
                $filters['coverage_issue'] = true;
            }
            if (preg_match('/\boccupied\b/i', $message) === 1) {
                $filters['occupied_properties_only'] = true;
            }
        }
        if (in_array($current->intent, $vacancyIntents, true) || in_array($previous->intent, $vacancyIntents, true)) {
            foreach (['apartment', 'villa', 'shop', 'office', 'space', 'labor camp', 'warehouse', 'land'] as $type) {
                if (preg_match('/\b'.preg_quote($type, '/').'s?\b/i', $message) === 1) {
                    $filters['property_type'] = str_replace(' ', '_', $type);
                    break;
                }
            }
            if (preg_match('/\bmaintenance|work orders?|repairs?\b/i', $message) === 1) {
                $filters['maintenance'] = true;
            }
            if (preg_match('/\blongest\b/i', $message) === 1) {
                $filters['longest_vacant'] = true;
            }
        }
        if (in_array($current->intent, $maintenanceIntents, true) || in_array($previous->intent, $maintenanceIntents, true)) {
            foreach (['apartment', 'villa', 'shop', 'office', 'space', 'labor camp', 'warehouse', 'land'] as $type) {
                if (preg_match('/\b'.preg_quote($type, '/').'s?\b/i', $message) === 1) {
                    $filters['property_type'] = str_replace(' ', '_', $type);
                    break;
                }
            }
            if (preg_match('/\bhigh[ -]?priority\b/i', $message) === 1) {
                $filters['priority'] = 'high';
            }
            if (preg_match('/\burgent\b/i', $message) === 1) {
                $filters['priority'] = 'urgent';
            }
            if (preg_match('/\bvacant\b/i', $message) === 1) {
                $filters['vacancy_status'] = 'vacant';
            }
            if (preg_match('/\boccupied\b/i', $message) === 1) {
                $filters['occupancy_status'] = 'occupied';
            }
            if (preg_match('/\b(?:older|more) than\s+(\d+)\s+days?/i', $message, $m) === 1) {
                $filters['age_gt'] = (int) $m[1];
            }
        }
        $agreementContextIntents = ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention', 'renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'];
        if (in_array($current->intent, $agreementContextIntents, true) || in_array($previous->intent, $agreementContextIntents, true)) {
            if (preg_match('/\btenant\s+(?:agreements?|renewals?)\b/i', $message) === 1) {
                $filters['agreement_type'] = 'tenant';
            } elseif (preg_match('/\bowner\s+(?:agreements?|renewals?)\b/i', $message) === 1) {
                $filters['agreement_type'] = 'owner';
            }
        }

        return new ZaakiyConversationContext(
            intent: $inherit && $this->isVague($current) ? $previous->intent : $current->intent,
            domain: $inherit && $this->isVague($current) ? $previous->domain : ($explicitDomain ?? $current->modules[0] ?? null),
            subject: $inherit && $this->isVague($current) ? $previous->subject : null,
            metric: $current->metrics[0] ?? ($inherit ? $previous->metric : null),
            entities: $inherit ? $previous->entities : [],
            timeRange: $comparisonRequested && $inherit && $previous->timeRange !== null ? $previous->timeRange : ($current->timeRange ?? ($inherit ? $previous->timeRange : null)),
            comparisonRange: $current->comparisonPeriod ?? ($inherit ? $previous->comparisonRange : null),
            filters: $filters,
            sort: $current->sort ? ['field' => $current->sort] : ($inherit ? $previous->sort : []),
            resultReferences: $inherit ? $previous->resultReferences : [],
            branchContext: ['branch_id' => $branchId, 'branch_code' => $branch->branch()->code],
            meta: [
                'context_reused' => $inherit,
                'branch_changed' => $branchChanged,
                'reset_reason' => $branchChanged ? 'branch_changed' : ($topicChanged ? 'topic_changed' : ($reset ? 'explicit_reset' : null)),
            ],
        );
    }

    public function complete(ZaakiyConversationContext $context, IntentFrame $intent, array $results, BranchContext $branch): ZaakiyConversationContext
    {
        $references = [];
        $entities = [];
        foreach ($results as $result) {
            if (! $result instanceof ZaakiySkillResult) {
                continue;
            }
            foreach (array_merge($result->sources, $result->records) as $record) {
                $reference = $this->reference($record);
                if ($reference !== null) {
                    $references[$reference['type'].':'.$reference['id']] = $reference;
                    if ($result->intent === 'entity_resolution' || ($intent->intent === 'property_360' && $reference['type'] === 'property') || ($intent->intent === 'tenant_360' && $reference['type'] === 'tenant') || ($intent->intent === 'owner_360' && $reference['type'] === 'owner') || ($intent->intent === 'agreement_360' && in_array($reference['type'], ['owner_agreement', 'tenant_agreement', 'owner', 'tenant', 'property'], true)) || (in_array($intent->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention', 'renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true) && in_array($reference['type'], ['owner_agreement', 'tenant_agreement', 'owner', 'tenant', 'property'], true)) || (in_array($intent->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) && in_array($reference['type'], ['property', 'owner', 'tenant_agreement'], true)) || (in_array($intent->intent, ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], true) && in_array($reference['type'], ['work_order', 'property', 'owner'], true)) || (in_array($intent->intent, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true) && in_array($reference['type'], ['tenant', 'tenant_agreement', 'property'], true))) {
                        $entities[$reference['type'].':'.$reference['id']] = $reference;
                    }
                }
            }
        }

        return $context->with([
            'intent' => $intent->intent,
            'domain' => $this->domain($intent) ?? $context->domain,
            'metric' => $intent->metrics[0] ?? $context->metric,
            'timeRange' => $intent->timeRange ?? $context->timeRange,
            'comparisonRange' => $intent->comparisonPeriod ?? $context->comparisonRange,
            'entities' => $entities ? array_values(array_slice($entities, 0, 10)) : $context->entities,
            'resultReferences' => array_values(array_slice($references ?: $context->resultReferences, 0, 25)),
            'branchContext' => ['branch_id' => $branch->id(), 'branch_code' => $branch->branch()->code],
            'meta' => array_merge($context->meta, ['result_reference_count' => count($references), 'explanation_requested' => $intent->explanationRequested, 'anomaly_requested' => $intent->anomalyRequested]),
        ]);
    }

    public function applyToIntent(IntentFrame $intent, ZaakiyConversationContext $context, string $message): IntentFrame
    {
        return new IntentFrame(
            intent: $context->intent ?? $intent->intent,
            modules: match (true) {
                $context->intent === 'agreement_360' && preg_match('/\bproperty\b/i', $message) === 1 && $this->hasEntity($context, 'property') => ['property_360'],
                $context->intent === 'agreement_360' && preg_match('/\btenant\b/i', $message) === 1 && $this->hasEntity($context, 'tenant') => ['tenant_360'],
                $context->intent === 'agreement_360' && preg_match('/\bowner\b/i', $message) === 1 && $this->hasEntity($context, 'owner') => ['owner_360'],
                in_array($context->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], true) && preg_match('/\b(?:agreement|first one|that one)\b/i', $message) === 1 && ($this->hasEntity($context, 'tenant_agreement') || $this->hasEntity($context, 'owner_agreement')) => ['agreement_360'],
                in_array($context->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true) && preg_match('/\b(?:agreement|first one|that one)\b/i', $message) === 1 && ($this->hasEntity($context, 'tenant_agreement') || $this->hasEntity($context, 'owner_agreement')) => ['agreement_360'],
                in_array($context->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) && preg_match('/\b(?:property|first one|that one)\b/i', $message) === 1 && $this->hasEntity($context, 'property') => ['property_360'],
                in_array($context->intent, ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], true) && preg_match('/\b(?:property|first one|that one)\b/i', $message) === 1 && $this->hasEntity($context, 'property') => ['property_360'],
                in_array($context->intent, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true) && preg_match('/\bproperty\b/i', $message) === 1 && $this->hasEntity($context, 'property') => ['property_360'],
                in_array($context->intent, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true) && preg_match('/\bagreement\b/i', $message) === 1 && $this->hasEntity($context, 'tenant_agreement') => ['agreement_360'],
                in_array($context->intent, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true) && preg_match('/\b(?:tenant|customer)\b/i', $message) === 1 && $this->hasEntity($context, 'tenant') => ['tenant_360'],
                $context->intent === 'property_360' => ['property_360'],
                $context->intent === 'tenant_360' => ['tenant_360'],
                $context->intent === 'owner_360' => ['owner_360'],
                $context->intent === 'agreement_360' => ['agreement_360'],
                in_array($context->intent, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention'], true) => ['agreement_risk'],
                in_array($context->intent, ['renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true) => ['renewal_intelligence'],
                in_array($context->intent, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true) => ['vacancy_analysis'],
                in_array($context->intent, ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], true) => ['maintenance_intelligence'],
                in_array($context->intent, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true) => ['collections_health'],
                default => $context->domain ? [$context->domain] : $intent->modules,
            },
            operation: $intent->operation,
            entities: $intent->entities,
            metrics: $context->metric ? [$context->metric] : $intent->metrics,
            filters: $context->filters,
            timeRange: $context->timeRange,
            comparisonPeriod: $context->comparisonRange,
            detailLevel: $intent->detailLevel,
            searchText: $intent->searchText,
            limit: $intent->limit,
            sort: $intent->sort,
            question: $message,
            comparisonRequested: $intent->comparisonRequested || preg_match('/compare|compared with|compared to|versus|\bvs\b|difference between|change from|same period last year/i', $message) === 1,
            explanationRequested: $intent->explanationRequested || preg_match('/\bwhy\b|what (?:caused|drove|changed|contributed)|reason for (?:the )?change/i', $message) === 1 || (bool) ($context->meta['explanation_requested'] ?? false),
            anomalyRequested: $intent->anomalyRequested || preg_match('/\banomal(?:y|ies)\b|\bunusual\b|outside normal|\babnormal\b|\bspike\b|drop unusually/i', $message) === 1 || (bool) ($context->meta['anomaly_requested'] ?? false),
        );
    }

    private function isFollowUp(string $message): bool
    {
        return preg_match('/\b(what about|show those|show them|only|which one|which is|why|compare|versus|difference|and|how about|make it|weekly|monthly|daily|quarterly)\b/i', $message) === 1
            || preg_match('/\b(above|below|over|under|highest|lowest|increase|decrease)\b/i', $message) === 1;
    }

    private function isVague(IntentFrame $intent): bool
    {
        return in_array($intent->intent, ['general.help', 'dashboard.summary'], true);
    }

    private function domain(IntentFrame $intent): ?string
    {
        $domain = $intent->modules[0] ?? null;

        if ($domain === 'property_360') {
            return 'properties';
        }
        if ($domain === 'tenant_360') {
            return 'customers';
        }
        if ($domain === 'owner_360') {
            return 'customers';
        }
        if ($domain === 'agreement_360') {
            return 'agreements';
        }
        if (in_array($domain, ['agreement_risk', 'agreement_attention', 'agreement_expiry', 'agreement_financial_attention', 'renewal_intelligence', 'renewal_candidates', 'renewals_due', 'renewal_attention'], true)) {
            return 'agreements';
        }
        if (in_array($domain, ['vacancy_analysis', 'occupancy_summary', 'vacant_properties', 'property_availability', 'upcoming_vacancy'], true)) {
            return 'properties';
        }
        if (in_array($domain, ['maintenance_intelligence', 'maintenance_backlog', 'maintenance_aging', 'maintenance_priority', 'maintenance_completion', 'maintenance_property_summary'], true)) {
            return 'maintenance';
        }
        if (in_array($domain, ['collections_health', 'collections_summary', 'outstanding_receivables', 'overdue_receivables', 'upcoming_collections', 'collection_cheques'], true)) {
            return 'accounts';
        }

        return $domain && ! in_array($domain, ['dashboard', 'general'], true) ? $domain : null;
    }

    private function addAmountFilter(array &$filters, string $message): void
    {
        if (preg_match('/\b(?:above|over|greater than|more than)\s+(?:aed\s*)?([\d,]+(?:\.\d+)?)/i', $message, $match) === 1) {
            $filters[preg_match('/\boverdue\b/i', $message) === 1 ? 'overdue_gt' : 'outstanding_gt'] = (float) str_replace(',', '', $match[1]);
        }
        if (preg_match('/\b(?:below|under|less than)\s+(?:aed\s*)?([\d,]+(?:\.\d+)?)/i', $message, $match) === 1) {
            $filters[preg_match('/\boverdue\b/i', $message) === 1 ? 'overdue_lt' : 'outstanding_lt'] = (float) str_replace(',', '', $match[1]);
        }
    }

    private function reference(array $record): ?array
    {
        $type = $record['type'] ?? $record['entity_type'] ?? null;
        $id = $record['id'] ?? $record['fields']['id'] ?? null;
        $label = $record['label'] ?? $record['reference'] ?? $record['fields']['agreement_no'] ?? $record['fields']['property_code'] ?? $record['fields']['customer_code'] ?? null;

        return is_string($type) && is_numeric($id) && $label ? ['type' => $type, 'id' => (int) $id, 'label' => (string) $label] : null;
    }

    private function hasEntity(ZaakiyConversationContext $context, string $type): bool
    {
        return collect($context->entities)->contains(fn (array $entity): bool => ($entity['type'] ?? null) === $type && is_numeric($entity['id'] ?? null));
    }
}
