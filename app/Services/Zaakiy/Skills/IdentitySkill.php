<?php

namespace App\Services\Zaakiy\Skills;

use App\Models\User;
use App\Services\Zaakiy\ZaakiySkill;
use App\Support\Branch\BranchContext;

class IdentitySkill implements ZaakiySkill
{
    public function matches(string $message): bool
    {
        return preg_match('/who are you|what are you|about zaakiy/i', $message) === 1;
    }

    public function run(string $message, User $user, BranchContext $branch): array
    {
        return ['skill' => 'identity', 'data' => ['assistant' => 'Zaakiy', 'creator' => 'Zv3 - ZaakiyV3RSE', 'purpose' => 'Operational intelligence for the Baithul Madeena ERP']];
    }
}
