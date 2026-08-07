<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;

/**
 * ADR-019: nas listas, membro comum faz tudo — quem vê o café acabando
 * adiciona na hora. Só apagar (destrutivo, sem volta na tela) é de admin.
 */
class ShoppingListPolicy
{
    public function viewAny(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function view(User $user, ShoppingList $list): bool
    {
        return $user->isMemberOf($list->household);
    }

    public function create(User $user, Household $household): bool
    {
        return $user->isMemberOf($household);
    }

    public function update(User $user, ShoppingList $list): bool
    {
        return $user->isMemberOf($list->household);
    }

    public function delete(User $user, ShoppingList $list): bool
    {
        return $user->isAdminOf($list->household);
    }
}
