<?php

namespace App\Actions\Invitations;

use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    /**
     * Aceita um convite a partir do token em claro recebido no link.
     *
     * @throws ValidationException
     */
    public function handle(string $token, User $user): Household
    {
        $invitation = Invitation::firstWhere('token_hash', CreateInvitation::hash($token));

        // Mesma mensagem para token inexistente, expirado ou revogado: não
        // entregamos a quem sonda o sistema a informação de qual é o caso.
        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'token' => 'Este convite não é mais válido. Peça um novo link a quem administra a casa.',
            ]);
        }

        $household = $invitation->household;

        if ($user->isMemberOf($household)) {
            throw ValidationException::withMessages([
                'token' => 'Você já faz parte desta casa.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $household, $user) {
            $household->members()->attach($user->id, [
                'role' => $invitation->role->value,
            ]);

            $invitation->accepted_at = now();
            $invitation->accepted_by = $user->id;
            $invitation->save();

            // Quem acabou de entrar numa casa quer vê-la: o clique no link
            // é intencional, então trocamos o contexto ativo.
            $user->active_household_id = $household->id;
            $user->save();

            return $household;
        });
    }
}
