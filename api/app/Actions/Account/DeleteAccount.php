<?php

namespace App\Actions\Account;

use App\Actions\Households\UpdateMemberRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    /**
     * Apaga a conta. Casas em que a pessoa era a única moradora vão junto;
     * casas com outras pessoas exigem que ela promova alguém antes, para
     * não deixar ninguém trancado numa casa sem administrador.
     *
     * @throws ValidationException
     */
    public function handle(User $user): void
    {
        $casas = $user->households()->get();

        $presas = $casas->filter(
            fn (Household $casa) => $casa->members()->count() > 1
                && $user->isAdminOf($casa)
                && UpdateMemberRole::ehUltimoAdmin($casa, $user)
        );

        if ($presas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => 'Você é o único administrador de: '.$presas->pluck('name')->join(', ')
                    .'. Promova outra pessoa a administrador antes de apagar sua conta.',
            ]);
        }

        DB::transaction(function () use ($user, $casas) {
            foreach ($casas as $casa) {
                if ($casa->members()->count() === 1) {
                    $casa->delete();
                }
            }

            $user->delete();
        });
    }
}
