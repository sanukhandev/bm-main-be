<?php

namespace App\Services\Zaakiy;

use App\Services\Zaakiy\DTOs\ZaakiyMetricTrend;
use App\Services\Zaakiy\DTOs\ZaakiyMetricTrendPoint;
use Carbon\CarbonImmutable;

final class TrendEngine
{
    public const MAX_POINTS = 24;

    public function __construct(private readonly MetricComparisonEngine $comparisons) {}

    public function periods(array $range, string $requested = 'auto', int $maxPoints = self::MAX_POINTS): array
    {
        $from = CarbonImmutable::parse($range['from'], config('app.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($range['to'], config('app.timezone'))->startOfDay();
        $granularity = $requested === 'auto' ? $this->defaultGranularity($from, $to) : $requested;
        if (! in_array($granularity, ['day', 'week', 'month', 'quarter'], true)) {
            return ['granularity' => $granularity, 'periods' => [], 'warnings' => [['code' => 'TREND_GRANULARITY_UNSUPPORTED']]];
        }
        $periods = $this->makePeriods($from, $to, $granularity);
        if (count($periods) > $maxPoints && $requested === 'auto') {
            foreach (['week', 'month', 'quarter'] as $coarser) {
                if ($this->rank($coarser) <= $this->rank($granularity)) {
                    continue;
                }
                $periods = $this->makePeriods($from, $to, $coarser);
                if (count($periods) <= $maxPoints) {
                    $granularity = $coarser;
                    break;
                }
            }
        }
        if (count($periods) > $maxPoints) {
            return ['granularity' => $granularity, 'periods' => [], 'warnings' => [['code' => 'TREND_RANGE_TOO_LARGE']]];
        }

        return ['granularity' => $granularity, 'periods' => $periods, 'warnings' => []];
    }

    public function build(string $metric, string $label, string $unit, string $granularity, array $range, array $points, array $meta = []): ZaakiyMetricTrend
    {
        $values = array_values(array_filter($points, static fn (ZaakiyMetricTrendPoint $point): bool => $point->value !== null));
        $summary = [];
        if ($values !== []) {
            $first = $values[0];
            $last = $values[count($values) - 1];
            $comparison = $this->comparisons->compare($metric, $label, $last->value, $first->value, $unit, ['from' => $last->periodStart, 'to' => $last->periodEnd], ['from' => $first->periodStart, 'to' => $first->periodEnd]);
            $numbers = array_map(static fn (ZaakiyMetricTrendPoint $point) => $point->value, $values);
            $minimum = min($numbers);
            $maximum = max($numbers);
            $minimumPoint = $values[array_search($minimum, $numbers, true)];
            $maximumPoint = $values[array_search($maximum, $numbers, true)];
            $summary = $comparison->toArray() + [
                'first_value' => $first->value,
                'last_value' => $last->value,
                'minimum_value' => $minimum,
                'minimum_period' => $minimumPoint->periodStart,
                'maximum_value' => $maximum,
                'maximum_period' => $maximumPoint->periodStart,
            ];
        }

        return new ZaakiyMetricTrend($metric, $label, $unit, $granularity, $range, $points, $summary, $meta);
    }

    private function makePeriods(CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $periods = [];
        $cursor = match ($granularity) {
            'day' => $from,
            'week' => $from->startOfWeek(),
            'month' => $from->startOfMonth(),
            'quarter' => $from->startOfQuarter(),
        };
        while ($cursor->lte($to)) {
            $end = match ($granularity) {
                'day' => $cursor,
                'week' => $cursor->endOfWeek()->startOfDay(),
                'month' => $cursor->endOfMonth()->startOfDay(),
                'quarter' => $cursor->endOfQuarter()->startOfDay(),
            };
            $start = $cursor->lt($from) ? $from : $cursor;
            $end = $end->gt($to) ? $to : $end;
            $periods[] = ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $cursor->format(match ($granularity) {
                'day' => 'Y-m-d', 'week' => 'o-\WW', 'month' => 'M Y', 'quarter' => 'Y \QQ',
            })];
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                'quarter' => $cursor->addQuarter(),
            };
        }

        return $periods;
    }

    private function defaultGranularity(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = $from->diffInDays($to) + 1;

        return $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    }

    private function rank(string $granularity): int
    {
        return ['day' => 1, 'week' => 2, 'month' => 3, 'quarter' => 4][$granularity];
    }
}
