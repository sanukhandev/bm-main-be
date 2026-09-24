<?php

namespace App\Services\Zaakiy;

use Carbon\CarbonImmutable;

class TimeRangeResolver
{
    public function resolve(string $message, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $query = mb_strtolower($message);

        return match (true) {
            preg_match('/today|todays|this day/', $query) === 1 => $this->range($now, $now),
            preg_match('/yesterday/', $query) === 1 => $this->range($now->subDay(), $now->subDay()),
            preg_match('/tomorrow/', $query) === 1 => $this->range($now->addDay(), $now->addDay()),
            preg_match('/next\s+7\s+days?/', $query) === 1 => $this->range($now, $now->addDays(7)),
            preg_match('/next\s+30\s+days?|expire|expir/', $query) === 1 => $this->range($now, $now->addDays(30)),
            preg_match('/last\s+30\s+days?/', $query) === 1 => $this->range($now->subDays(30), $now),
            preg_match('/this\s+month/', $query) === 1 => $this->range($now->startOfMonth(), $now->endOfMonth()),
            preg_match('/last\s+month/', $query) === 1 => $this->range($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()),
            preg_match('/next\s+month/', $query) === 1 => $this->range($now->addMonth()->startOfMonth(), $now->addMonth()->endOfMonth()),
            preg_match('/this\s+year/', $query) === 1 => $this->range($now->startOfYear(), $now->endOfYear()),
            preg_match('/last\s+year/', $query) === 1 => $this->range($now->subYear()->startOfYear(), $now->subYear()->endOfYear()),
            default => null,
        };
    }

    public function comparison(string $message, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $query = mb_strtolower($message);
        if (preg_match('/last\s+month/', $query) === 1) {
            return $this->range($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth());
        }
        if (preg_match('/last\s+30\s+days?/', $query) === 1) {
            return $this->range($now->subDays(30), $now);
        }
        if (preg_match('/last\s+week/', $query) === 1) {
            return $this->range($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek());
        }

        return null;
    }

    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
