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
            throw self::conviteInvalido();
        }

        $household = $invitation->household;

        if ($user->isMemberOf($household)) {
            throw ValidationException::withMessages([
                'token' => 'Você já faz parte desta casa.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $household, $user) {
            // A verificação acima é só para a mensagem amigável. A garantia
            // de uso único é este UPDATE condicional: entre a leitura e a
            // escrita, outra requisição pode ter consumido o convite, e só
            // quem afeta 1 linha aqui tem direito de entrar na casa.
            $reivindicado = Invitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'accepted_at' => now(),
                    'accepted_by' => $user->id,
                ]);

            if ($reivindicado === 0) {
                throw self::conviteInvalido();
            }

            $household->members()->attach($user->id, [
                'role' => $invitation->role->value,
            ]);

            // Quem acabou de entrar numa casa quer vê-la: o clique no link
            // é intencional, então trocamos o contexto ativo.
            $user->active_household_id = $household->id;
            $user->save();

            return $household;
        });
    }

    private static function conviteInvalido(): ValidationException
    {
        return ValidationException::withMessages([
            'token' => 'Este convite não é mais válido. Peça um novo link a quem administra a casa.',
        ]);
    }
}
