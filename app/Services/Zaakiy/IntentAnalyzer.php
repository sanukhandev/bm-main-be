<?php

namespace App\Services\Zaakiy;

class IntentAnalyzer
{
    public function __construct(private readonly TimeRangeResolver $timeRanges) {}

    public function analyze(string $message, array $history = []): IntentFrame
    {
        $query = mb_strtolower(trim($message));
        $previous = $this->previousQuestion($history);
        $effective = $query;
        if ($previous !== null && preg_match('/^(what about|and|how about|next|previous)/', $query) === 1) {
            $effective .= ' '.$previous;
        }

        $timeRange = $this->timeRanges->resolve($effective);
        $explanationRequested = preg_match('/\bwhy\b|what (?:caused|drove|changed|contributed)|reason for (?:the )?change/i', $query) === 1;
        $anomalyRequested = preg_match('/\banomal(?:y|ies)\b|\bunusual\b|outside normal|\babnormal\b|\bspike\b|drop unusually/i', $query) === 1;
        $comparisonRequested = preg_match('/compare|compared with|compared to|versus|\bvs\b|difference between|change from|same period last year|\bthan\s+(?:january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $query) === 1;
        $trendRequested = $this->isTrendRequest($query) && ! $comparisonRequested;
        $comparisonPeriod = $comparisonRequested
            ? $this->timeRanges->comparison($effective, null, $timeRange)
            : null;
        if ($explanationRequested && $comparisonPeriod === null && $timeRange !== null) {
            $comparisonPeriod = $this->timeRanges->previousPeriod($timeRange);
            $comparisonRequested = true;
        }
        if ($anomalyRequested && $timeRange === null) {
            $timeRange = $this->timeRanges->resolve('this month');
            $comparisonPeriod = $this->timeRanges->comparison('last month', null, $timeRange);
            $comparisonRequested = $comparisonPeriod !== null;
        }
        $modules = [];
        $intent = 'general.help';
        $operation = 'explain';
        $metrics = [];
        $entities = [];

        if (preg_match('/who are you|what are you|about zaakiy/', $query) === 1) {
            return new IntentFrame('identity', ['identity'], 'explain', question: $message);
        }
        if (preg_match('/management briefing|executive summary|business summary|branch summary|what(?:\'s| is) happening(?: today| this week| this month)?|what needs (?:my )?attention|how is the (?:branch|business) doing|anything important/i', $query) === 1) {
            $modules = ['management_briefing'];
            $intent = 'management_briefing';
            $operation = 'aggregate';
        } elseif ($this->isProperty360($query, $previous)) {
            $modules = ['property_360'];
            $intent = 'property_360';
            $operation = 'detail';
        } elseif ($this->isVacancyAnalysis($query, $previous)) {
            $modules = ['vacancy_analysis'];
            $intent = preg_match('/\b(?:become|becomes|expected)\s+(?:vacant|available)|\bupcoming vacancy\b/i', $query) === 1
                ? 'upcoming_vacancy'
                : (preg_match('/\boccupancy rate\b|\boccupied\s+vs\s+vacant\b|\bhow is occupancy\b/i', $query) === 1 ? 'occupancy_summary' : 'vacancy_analysis');
            $operation = 'search';
        } elseif ($this->isAgreement360($query, $previous)) {
            $modules = ['agreement_360'];
            $intent = 'agreement_360';
            $operation = 'detail';
        } elseif ($this->isRenewalIntelligence($query)) {
            $modules = ['renewal_intelligence'];
            $intent = 'renewal_intelligence';
            $operation = 'search';
        } elseif ($this->isMaintenanceIntelligence($query)) {
            $modules = ['maintenance_intelligence'];
            $intent = preg_match('/\bcompleted\b/i', $query) === 1 ? 'maintenance_completion'
                : (preg_match('/\b(?:properties|property)\b.*\b(?:most|issues|work orders|maintenance)\b/i', $query) === 1 ? 'maintenance_property_summary'
                    : (preg_match('/\b(?:oldest|older than|more than \d+ days|aging|age)\b/i', $query) === 1 ? 'maintenance_aging'
                    : (preg_match('/\b(?:high[ -]?priority|urgent)\b/i', $query) === 1 ? 'maintenance_priority' : 'maintenance_backlog')));
            $operation = 'search';
        } elseif ($this->isAgreementRisk($query)) {
            $modules = ['agreement_risk'];
            $intent = preg_match('/\b(?:expire|expiring|expiry)\b/i', $query) === 1 ? 'agreement_expiry' : 'agreement_risk';
            $operation = 'search';
        } elseif ($this->isCollectionsHealth($query, $previous)) {
            $modules = ['collections_health'];
            $intent = $this->collectionsIntent($query);
            $operation = in_array($intent, ['outstanding_receivables', 'overdue_receivables'], true) && preg_match('/who|which|show|largest|most|top|tenant/', $query) === 1 ? 'rank' : 'aggregate';
            $metrics[] = match ($intent) {
                'collections_summary' => 'collected_amount',
                'outstanding_receivables' => 'outstanding_amount',
                'overdue_receivables' => 'overdue_amount',
                default => 'scheduled_due',
            };
        } elseif ($this->isOwner360($query, $previous)) {
            $modules = ['owner_360'];
            $intent = 'owner_360';
            $operation = 'detail';
        } elseif ($this->isTenant360($query, $previous)) {
            $modules = ['tenant_360'];
            $intent = 'tenant_360';
            $operation = 'detail';
        } elseif (preg_match('/faq|frequently asked|how do i|how can i|user guide|manual|help|support/', $effective) === 1) {
            $modules = ['faq'];
            $intent = 'help.faq';
            $operation = 'explain';
        } elseif (preg_match('/intelligent report|management report|operational profit|profit loss|leakage|management analysis/', $effective) === 1) {
            $modules = ['intelligent_report'];
            $intent = 'report.intelligent_summary';
            $operation = 'analyze';
        } elseif (preg_match('/expire|expiring|renew/', $effective) === 1) {
            $modules = ['agreements'];
            $intent = preg_match('/outstanding|owe|owed|due/', $effective) === 1 ? 'agreement.expiring_with_outstanding' : 'agreement.expiring';
            $operation = 'search_and_enrich';
            $entities[] = preg_match('/owner/', $effective) === 1 && preg_match('/tenant/', $effective) !== 1 ? 'owner_agreement' : 'tenant_agreement';
            if ($intent === 'agreement.expiring_with_outstanding') {
                $modules[] = 'accounts';
            }
        } elseif (preg_match('/today.*(inward|collection|received)|inward.*today/', $effective) === 1) {
            $modules = ['accounts'];
            $intent = 'financial.collection_summary';
            $operation = 'aggregate';
            $metrics[] = 'inward_amount';
        } elseif (preg_match('/compare|versus|vs|difference/', $effective) === 1 && preg_match('/collection|inward|outward|payment/', $effective) === 1) {
            $modules = ['accounts'];
            $intent = 'financial.collection_comparison';
            $operation = 'compare';
            $metrics[] = 'inward_amount';
        } elseif (preg_match('/outstanding|receivable|payable|overdue|owe|owed/', $effective) === 1) {
            $modules = ['accounts'];
            $intent = preg_match('/overdue/', $effective) === 1 ? 'financial.overdue' : 'financial.outstanding';
            $operation = 'aggregate';
            $metrics[] = 'outstanding_amount';
            if (preg_match('/tenant/', $effective) === 1) {
                $entities[] = 'tenant';
            }
            if (preg_match('/owner/', $effective) === 1) {
                $entities[] = 'owner';
            }
        } elseif (preg_match('/cheque/', $effective) === 1) {
            $modules = ['accounts'];
            $intent = preg_match('/pending/', $effective) === 1 ? 'financial.pending_cheques' : 'financial.cheque_status';
            $operation = 'search';
        } elseif (preg_match('/occupied|vacant|available/', $effective) === 1 && preg_match('/maintenance|work order|repair/', $effective) === 1) {
            $modules = ['properties', 'maintenance'];
            $intent = 'property.with_open_maintenance';
            $operation = 'search_and_enrich';
        } elseif (preg_match('/work order|maintenance|repair/', $effective) === 1) {
            $modules = ['maintenance'];
            $intent = 'maintenance.open';
            $operation = 'search';
        } elseif (preg_match('/property|properties|occupancy/', $effective) === 1) {
            $modules = ['properties'];
            $intent = preg_match('/available|vacant/', $effective) === 1 ? 'property.availability' : 'property.occupancy';
            $operation = 'aggregate';
        } elseif (preg_match('/owner|tenant|customer/', $effective) === 1) {
            $modules = ['customers'];
            $intent = preg_match('/agreement/', $effective) === 1 ? 'customer.agreements' : 'customer.details';
            $operation = 'search';
            $entities[] = preg_match('/tenant/', $effective) === 1 ? 'tenant' : 'owner';
        } elseif (preg_match('/agreement|lease/', $effective) === 1) {
            $modules = ['agreements'];
            $intent = 'agreement.status_summary';
            $operation = 'aggregate';
        } elseif (preg_match('/invoice|quotation|billing/', $effective) === 1) {
            $modules = ['billing'];
            $intent = 'report.summary';
            $operation = 'aggregate';
        } elseif (preg_match('/audit|activity history|who changed/', $effective) === 1) {
            $modules = ['audit'];
            $intent = 'audit.recent_activity';
            $operation = 'search';
        } elseif (preg_match('/dashboard|overview|summary|kpi|how many/', $effective) === 1) {
            $modules = ['dashboard'];
            $intent = 'dashboard.summary';
            $operation = 'aggregate';
        }

        $filters = $trendRequested ? ['trend' => true, 'trend_granularity' => $this->trendGranularity($query)] : [];

        return new IntentFrame($intent, array_values(array_unique($modules ?: ['dashboard'])), $operation, $entities, $metrics, $filters, $timeRange, $comparisonPeriod, preg_match('/show|list|which/', $query) === 1 ? 'detail' : 'summary', null, 10, null, $message, $comparisonRequested, $explanationRequested, $anomalyRequested);
    }

    private function previousQuestion(array $history): ?string
    {
        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? null) === 'user' && is_string($item['text'] ?? null)) {
                return mb_substr($item['text'], 0, 500);
            }
        }

        return null;
    }

    private function isTrendRequest(string $query): bool
    {
        return preg_match('/\btrend\b|over time|month by month|week by week|each month|each week|by month|by week|history of|over the last \d+\s+(?:days?|weeks?|months?)|how have .*\bchanged\b/i', $query) === 1
            || preg_match('/\b(?:daily|weekly|monthly|quarterly)\b/i', $query) === 1;
    }

    private function trendGranularity(string $query): string
    {
        return match (true) {
            preg_match('/\b(?:daily|day by day)\b/i', $query) === 1 => 'day',
            preg_match('/\b(?:weekly|week by week)\b/i', $query) === 1 => 'week',
            preg_match('/\b(?:monthly|month by month|each month)\b/i', $query) === 1 => 'month',
            preg_match('/\b(?:quarterly|quarter by quarter)\b/i', $query) === 1 => 'quarter',
            default => 'auto',
        };
    }

    private function isProperty360(string $query, ?string $previous): bool
    {
        if ($previous !== null && preg_match('/\b(?:does (?:it|this property)|show its|what is its|this property|that property)\b/i', $query) === 1) {
            return true;
        }

        if (preg_match('/\b(?:tell me about|give me (?:a )?(?:summary|the details)|what(?:\'s| is) happening with|what(?:\'s| is) the status of|show (?:me )?(?:the )?details? for|show property)\b/i', $query) !== 1) {
            return false;
        }

        return preg_match('/\b(?:P[-_][A-Z0-9-]+|property\s+#?\d+|flat\s+\w+|villa\s+\w+|shop\s+\w+|office\s+\w+|warehouse\s+\w+|apartment\s+\w+|space\s+\w+)\b/i', $query) === 1;
    }

    private function isVacancyAnalysis(string $query, ?string $previous): bool
    {
        if ($previous !== null && preg_match('/\bowner\b/i', $previous) === 1 && preg_match('/\b(?:property|properties|vacant|vacancy)\b/i', $query) === 1) {
            return false;
        }

        return preg_match('/\b(?:vacant|vacancy|occupancy|occupied\s+vs\s+vacant|available)\b/i', $query) === 1
            && preg_match('/\b(?:property|properties|apartments?|villas?|shops?|offices?|spaces?|owners?|rate|longest|maintenance|next month|next \d+ days?)\b/i', $query) === 1;
    }

    private function isTenant360(string $query, ?string $previous): bool
    {
        if ($previous !== null && preg_match('/^(?:what about|how about)\b/i', $query) === 1) {
            if (preg_match('/\b(?:which|tenant)\s+agreements?\b|\bexpire|expiring\b/i', $previous) === 1) {
                return false;
            }

            return preg_match('/\b(?:property|flat|villa|shop|office|warehouse|apartment)\b/i', $previous) !== 1;
        }
        if (preg_match('/\b(?:tenant\s+agreements?|agreements?)\b.*\b(?:expire|expiring|renew|how many|which|list)\b/i', $query) === 1) {
            return false;
        }
        if ($previous !== null && preg_match('/\b(?:what property|what agreement|does (?:he|she|they)|(?:he|she|they)\s+(?:owe|rent)|their agreement)\b/i', $query) === 1) {
            return true;
        }

        if (preg_match('/\btenant\b|\brenting\b|\bowe(?:s|d)?\b|\boverdue payments?\b|\bpending cheques?\b|\brecent agreements?\b/i', $query) === 1) {
            return true;
        }

        return preg_match('/\b(?:tell me about|give me (?:a )?(?:summary|the details) of|show everything important about)\b/i', $query) === 1
            && preg_match('/\b(?:property|flat|villa|shop|office|warehouse|apartment)\b/i', $query) !== 1;
    }

    private function isOwner360(string $query, ?string $previous): bool
    {
        if ($previous !== null && preg_match('/\b(?:which properties|vacant|owner agreement|next owner payment|owe|maintenance)\b/i', $query) === 1) {
            return preg_match('/\bowner\b/i', $previous) === 1;
        }

        if (preg_match('/\bowner\b/i', $query) !== 1) {
            return false;
        }

        return preg_match('/\b(?:tell me about|give me (?:a )?(?:summary|the details) of|show everything important about|show owner|how many properties|which properties|active owner agreements|do we owe|next owner payment|owner agreements? expiring|maintenance issues)\b/i', $query) === 1;
    }

    private function isAgreement360(string $query, ?string $previous): bool
    {
        if (preg_match('/\b(?:TA|OA)[-_][A-Z0-9-]+\b|\b(?:this|that)\s+agreement\b/i', $query) === 1) {
            return true;
        }

        if ($previous === null || preg_match('/\b(?:TA|OA)[-_][A-Z0-9-]+\b/i', $previous) !== 1) {
            return false;
        }

        return preg_match('/\b(?:this|that|the)\s+agreement\b|\b(?:expire|active|outstanding|overdue|installment|payment|cheque|property|tenant|owner|status)\b/i', $query) === 1;
    }

    private function isAgreementRisk(string $query): bool
    {
        if (preg_match('/\bagreements?\b/i', $query) !== 1) {
            return false;
        }
        if (preg_match('/\bexpire\b.*\bstill owe money\b/i', $query) === 1) {
            return false;
        }

        return preg_match('/\b(?:need attention|attention|risk|expir(?:e|ing|y)|overdue|outstanding|bounced cheques?|review this week|coverage)\b/i', $query) === 1;
    }

    private function isRenewalIntelligence(string $query): bool
    {
        return preg_match('/\brenew(?:al|als|ing)?\b/i', $query) === 1
            && preg_match('/\b(?:agreement|agreements|tenant|owner|expir|overdue|outstanding|cheque|review|property|properties)\b/i', $query) === 1;
    }

    private function isMaintenanceIntelligence(string $query): bool
    {
        if (preg_match('/\b(?:maintenance|work orders?|repairs?)\b/i', $query) !== 1) {
            return false;
        }

        return preg_match('/\b(?:how is|backlog|open|oldest|older than|more than \d+ days|high-?priority|urgent|completed|properties|vacant|occupied|attention|most|maintenance issues)\b/i', $query) === 1;
    }

    private function isCollectionsHealth(string $query, ?string $previous): bool
    {
        if (preg_match('/\b(?:owner|owners|outward|pay owners|owe owners|owner payable)\b/i', $query) === 1) {
            return false;
        }

        if (preg_match('/\b(?:expire|expiring|renew)\b/i', $query) === 1 && preg_match('/\bagreements?\b/i', $query) === 1) {
            return false;
        }

        return preg_match('/\b(?:collections?|collect|collected|inward|received|outstanding|receivable|overdue|owe(?:s|d)?|unpaid installments?|due this|pending cheques?|bounced cheques?|largest outstanding|top outstanding|how healthy)\b/i', $query) === 1;
    }

    private function collectionsIntent(string $query): string
    {
        if (preg_match('/\b(?:pending|bounced|deposited|cleared|cancelled)\s+cheques?\b|\bcheques?\b/i', $query) === 1) {
            return 'collection_cheques';
        }
        if (preg_match('/\b(?:due|scheduled)\b.*\b(?:today|tomorrow|this|next|week|month|installment)/i', $query) === 1) {
            return 'upcoming_collections';
        }
        if (preg_match('/\b(?:overdue|past due)\b/i', $query) === 1) {
            return 'overdue_receivables';
        }
        if (preg_match('/\b(?:outstanding|receivable|owe(?:s|d)?|largest outstanding|top outstanding)\b/i', $query) === 1) {
            return 'outstanding_receivables';
        }

        return preg_match('/\b(?:collection|collections|collect|collected|inward|received|paid)\b/i', $query) === 1 ? 'collections_summary' : 'collections_health';
    }
}
