<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveMember
{
    /**
     * @throws ValidationException
     */
    public function handle(Household $household, User $member): void
    {
        $papel = $household->roleOf($member);

        if ($papel === null) {
            throw ValidationException::withMessages([
                'member' => 'Esta pessoa não faz parte da casa.',
            ]);
        }

        if ($papel->isAdmin() && UpdateMemberRole::ehUltimoAdmin($household, $member)) {
            throw ValidationException::withMessages([
                'member' => 'A casa precisa de pelo menos um administrador. Promova outra pessoa antes de sair.',
            ]);
        }

        DB::transaction(function () use ($household, $member) {
            $household->members()->detach($member->id);

            // Quem sai não pode continuar com a casa em contexto: cai na
            // primeira casa restante, ou em nenhuma.
            if ($member->active_household_id === $household->id) {
                $member->active_household_id = $member->households()->value('households.id');
                $member->save();
            }
        });
    }
}
