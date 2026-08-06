<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria uma casa e torna o usuário seu administrador.
 *
 * Regra de negócio vive aqui, não no controller: o registro web e, no
 * futuro, qualquer outro ponto de entrada usam esta mesma classe.
 */
class CreateHouseholdForUser
{
    public function handle(User $user, ?string $name = null): Household
    {
        return DB::transaction(function () use ($user, $name) {
            $household = Household::create([
                'name' => $name ?? self::defaultName($user),
            ]);

            $household->members()->attach($user->id, [
                'role' => HouseholdRole::Admin->value,
            ]);

            $user->active_household_id = $household->id;
            $user->save();

            return $household;
        });
    }

    /** "Carlos Bionde" vira "Casa de Carlos". */
    private static function defaultName(User $user): string
    {
        $primeiroNome = Str::before(trim($user->name), ' ');

        return 'Casa de '.($primeiroNome !== '' ? $primeiroNome : $user->name);
    }
}
