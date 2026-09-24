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
        $comparisonPeriod = preg_match('/compare|versus|vs|difference/', $effective) === 1
            ? $this->timeRanges->comparison($effective)
            : null;
        $modules = [];
        $intent = 'general.help';
        $operation = 'explain';
        $metrics = [];
        $entities = [];

        if (preg_match('/who are you|what are you|about zaakiy/', $query) === 1) {
            return new IntentFrame('identity', ['identity'], 'explain', question: $message);
        }
        if (preg_match('/intelligent report|management report|operational profit|profit loss|leakage|management analysis/', $effective) === 1) {
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

        return new IntentFrame($intent, array_values(array_unique($modules ?: ['dashboard'])), $operation, $entities, $metrics, [], $timeRange, $comparisonPeriod, preg_match('/show|list|which/', $query) === 1 ? 'detail' : 'summary', null, 10, null, $message);
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
}
