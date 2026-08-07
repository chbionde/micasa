<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveMember
{
    /**
     * Remove alguém da casa. Se era a última pessoa, a casa vai junto —
     * casa vazia não serve para nada e ninguém conseguiria entrar nela.
     *
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

        $ehUltimaPessoa = $household->members()->count() === 1;

        // Com outras pessoas na casa, alguém precisa continuar administrando.
        if (! $ehUltimaPessoa && $papel->isAdmin() && UpdateMemberRole::ehUltimoAdmin($household, $member)) {
            throw ValidationException::withMessages([
                'member' => 'A casa precisa de pelo menos um administrador. Promova outra pessoa antes de sair.',
            ]);
        }

        DB::transaction(function () use ($household, $member, $ehUltimaPessoa) {
            if ($ehUltimaPessoa) {
                // O delete em cascata leva vínculo e convites; quem tinha a
                // casa como ativa fica sem casa ativa (nullOnDelete).
                $household->delete();
                $member->refresh();
            } else {
                $household->members()->detach($member->id);
            }

            if ($member->active_household_id === $household->id || $member->active_household_id === null) {
                $member->active_household_id = $member->households()->value('households.id');
                $member->save();
            }
        });
    }
}
