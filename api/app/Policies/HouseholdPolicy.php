<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    /** Qualquer membro enxerga a casa e quem mora nela. */
    public function view(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    /** Renomear a casa e mudar o fuso é coisa de admin. */
    public function update(User $user, Household $household): bool
    {
        return $user->isAdminOf($household);
    }

    /** Convidar, revogar convite e mexer em membros é coisa de admin. */
    public function manageMembers(User $user, Household $household): bool
    {
        return $user->isAdminOf($household);
    }
}
