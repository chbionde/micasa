<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateMemberRole
{
    /**
     * @throws ValidationException
     */
    public function handle(Household $household, User $member, HouseholdRole $role): void
    {
        $papelAtual = $household->roleOf($member);

        if ($papelAtual === null) {
            throw ValidationException::withMessages([
                'member' => 'Esta pessoa não faz parte da casa.',
            ]);
        }

        if ($papelAtual === $role) {
            return;
        }

        // Casa sem admin fica órfã: ninguém convida, ninguém gerencia.
        if ($papelAtual->isAdmin() && self::ehUltimoAdmin($household, $member)) {
            throw ValidationException::withMessages([
                'papel' => 'A casa precisa de pelo menos um administrador. Promova outra pessoa antes.',
            ]);
        }

        $household->members()->updateExistingPivot($member->id, ['role' => $role->value]);
    }

    public static function ehUltimoAdmin(Household $household, User $member): bool
    {
        return $household->admins()->where('users.id', '!=', $member->id)->doesntExist();
    }
}
