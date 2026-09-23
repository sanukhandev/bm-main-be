<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class GeneralSkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return true;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        return ['skill' => 'general', 'data' => ['supported_topics' => ['customers', 'properties', 'agreements', 'accounts', 'maintenance', 'dashboard'], 'instruction' => 'Ask about one supported ERP topic to retrieve verified branch data.'], 'navigation' => ['label' => 'Open Dashboard', 'url' => '/app/dashboard']];
    }
}
