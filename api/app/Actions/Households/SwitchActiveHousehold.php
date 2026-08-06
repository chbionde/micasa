<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SwitchActiveHousehold
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, Household $household): void
    {
        // Trocar de contexto não é atalho para entrar em casa alheia.
        if (! $user->isMemberOf($household)) {
            throw ValidationException::withMessages([
                'household_id' => 'Você não faz parte desta casa.',
            ]);
        }

        $user->active_household_id = $household->id;
        $user->save();
    }
}
