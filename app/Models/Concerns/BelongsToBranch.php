<?php

namespace App\Models\Concerns;

use App\Support\Branch\BranchContext;

trait BelongsToBranch
{
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        return $query
            ->where($this->qualifyColumn($field), $value)
            ->where($this->qualifyColumn('branch_id'), app(BranchContext::class)->id());
    }
}
