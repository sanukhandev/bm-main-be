<?php

namespace App\Services\Zaakiy;

use Carbon\CarbonImmutable;

class TimeRangeResolver
{
    public function resolve(string $message, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $query = mb_strtolower($message);

        if (preg_match('/(?:from|between)\s+(\d{4}-\d{2}-\d{2})\s+(?:to|and)\s+(\d{4}-\d{2}-\d{2})/', $query, $match) === 1) {
            return ['from' => $match[1], 'to' => $match[2]];
        }

        if (preg_match('/(?:from|between)\s+(january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+(\d{4}))?\s+(?:to|and)\s+(january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+(\d{4}))?/i', $query, $match) === 1) {
            $from = CarbonImmutable::parse($match[1].' '.($match[2] ?? $now->year), config('app.timezone'))->startOfMonth();
            $to = CarbonImmutable::parse($match[3].' '.($match[4] ?? $from->year), config('app.timezone'))->endOfMonth();

            return $this->range($from, $to);
        }

        $months = $this->monthRanges($query, $now);

        return match (true) {
            $months !== [] => $months[0],
            preg_match('/today|todays|this day/', $query) === 1 => $this->range($now, $now),
            preg_match('/yesterday/', $query) === 1 => $this->range($now->subDay(), $now->subDay()),
            preg_match('/tomorrow/', $query) === 1 => $this->range($now->addDay(), $now->addDay()),
            preg_match('/next\s+(\d+)\s+days?/', $query, $match) === 1 => $this->range($now, $now->addDays((int) $match[1])),
            preg_match('/last\s+(\d+)\s+days?/', $query, $match) === 1 => $this->range($now->subDays((int) $match[1] - 1), $now),
            preg_match('/last\s+(\d+)\s+weeks?/', $query, $match) === 1 => $this->range($now->subWeeks((int) $match[1] - 1)->startOfWeek(), $now->endOfWeek()),
            preg_match('/last\s+(\d+)\s+months?/', $query, $match) === 1 => $this->range($now->subMonths((int) $match[1] - 1)->startOfMonth(), $now->endOfMonth()),
            preg_match('/next\s+3\s+months?/', $query) === 1 => $this->range($now, $now->addMonths(3)),
            preg_match('/this\s+week/', $query) === 1 => $this->range($now->startOfWeek(), $now->endOfWeek()),
            preg_match('/last\s+week/', $query) === 1 => $this->range($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()),
            preg_match('/next\s+week/', $query) === 1 => $this->range($now->addWeek()->startOfWeek(), $now->addWeek()->endOfWeek()),
            preg_match('/next\s+30\s+days?|expire|expir/', $query) === 1 => $this->range($now, $now->addDays(30)),
            preg_match('/last\s+30\s+days?/', $query) === 1 => $this->range($now->subDays(30), $now),
            preg_match('/this\s+month/', $query) === 1 => $this->range($now->startOfMonth(), $now->endOfMonth()),
            preg_match('/last\s+month/', $query) === 1 => $this->range($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()),
            preg_match('/next\s+month/', $query) === 1 => $this->range($now->addMonth()->startOfMonth(), $now->addMonth()->endOfMonth()),
            preg_match('/this\s+quarter/', $query) === 1 => $this->range($now->startOfQuarter(), $now->endOfQuarter()),
            preg_match('/last\s+quarter/', $query) === 1 => $this->range($now->subQuarter()->startOfQuarter(), $now->subQuarter()->endOfQuarter()),
            preg_match('/next\s+quarter/', $query) === 1 => $this->range($now->addQuarter()->startOfQuarter(), $now->addQuarter()->endOfQuarter()),
            preg_match('/this\s+year/', $query) === 1 => $this->range($now->startOfYear(), $now->endOfYear()),
            preg_match('/year\s+to\s+date|\bytd\b/', $query) === 1 => $this->range($now->startOfYear(), $now),
            preg_match('/last\s+year/', $query) === 1 => $this->range($now->subYear()->startOfYear(), $now->subYear()->endOfYear()),
            default => null,
        };
    }

    public function comparison(string $message, ?CarbonImmutable $now = null, ?array $primary = null): ?array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $query = mb_strtolower($message);
        if (preg_match('/same\s+period\s+last\s+year/', $query) === 1) {
            $primary ??= $this->resolve($message, $now);

            return $primary ? ['from' => CarbonImmutable::parse($primary['from'])->subYear()->toDateString(), 'to' => CarbonImmutable::parse($primary['to'])->subYear()->toDateString()] : null;
        }
        $months = $this->monthRanges($query, $now);
        if (count($months) > 1) {
            return $months[1];
        }
        if (count($months) === 1 && preg_match('/compare|compared|versus|\bvs\b|\bthan\b/i', $query) === 1) {
            return $months[0];
        }
        if (preg_match('/last\s+month/', $query) === 1) {
            return $this->range($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth());
        }
        if (preg_match('/last\s+30\s+days?/', $query) === 1) {
            return $this->range($now->subDays(30), $now);
        }
        if (preg_match('/last\s+week/', $query) === 1) {
            return $this->range($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek());
        }
        if (preg_match('/next\s+month/', $query) === 1) {
            return $this->range($now->addMonth()->startOfMonth(), $now->addMonth()->endOfMonth());
        }
        if (preg_match('/(?:previous|last)\s+quarter/', $query) === 1) {
            return $this->range($now->subQuarter()->startOfQuarter(), $now->subQuarter()->endOfQuarter());
        }
        if (preg_match('/next\s+quarter/', $query) === 1) {
            return $this->range($now->addQuarter()->startOfQuarter(), $now->addQuarter()->endOfQuarter());
        }
        if (preg_match('/same\s+period\s+last\s+year/', $query) === 1 && $primary) {
            return ['from' => CarbonImmutable::parse($primary['from'])->subYear()->toDateString(), 'to' => CarbonImmutable::parse($primary['to'])->subYear()->toDateString()];
        }

        return null;
    }

    public function previousPeriod(array $range): array
    {
        $from = CarbonImmutable::parse($range['from'], config('app.timezone'));
        $to = CarbonImmutable::parse($range['to'], config('app.timezone'));
        if ($from->isStartOfMonth() && $to->isEndOfMonth()) {
            return $this->range($from->subMonth()->startOfMonth(), $from->subMonth()->endOfMonth());
        }
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();

        return $this->range($previousTo->subDays($days - 1), $previousTo);
    }

    private function monthRanges(string $query, CarbonImmutable $now): array
    {
        preg_match_all('/\b(january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+(\d{4}))?\b/i', $query, $matches, PREG_SET_ORDER);
        $ranges = [];
        foreach ($matches as $match) {
            $month = CarbonImmutable::parse($match[1].' '.($match[2] ?? $now->year), config('app.timezone'));
            $ranges[] = $this->range($month->startOfMonth(), $month->endOfMonth());
        }

        return $ranges;
    }

    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
