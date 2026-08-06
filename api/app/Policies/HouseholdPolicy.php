<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    /** Qualquer membro enxerga a casa. */
    public function view(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    /** Convidar, revogar convite e mexer em membros é coisa de admin. */
    public function manageMembers(User $user, Household $household): bool
    {
        return $user->isAdminOf($household);
    }
}
