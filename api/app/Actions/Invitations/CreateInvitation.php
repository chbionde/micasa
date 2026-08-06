<?php

namespace App\Actions\Invitations;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Str;

class CreateInvitation
{
    public const VALIDADE_EM_DIAS = 7;

    /**
     * Gera um convite e devolve o token em claro junto.
     *
     * O token em claro existe apenas neste retorno: o banco guarda só o
     * hash, então nem nós conseguimos recuperá-lo depois. Perdeu o link,
     * gera outro.
     *
     * @return array{invitation: Invitation, token: string}
     */
    public function handle(
        Household $household,
        User $creator,
        HouseholdRole $role = HouseholdRole::Member,
    ): array {
        $token = Str::random(40);

        $invitation = Invitation::create([
            'household_id' => $household->id,
            'token_hash' => self::hash($token),
            'role' => $role,
            'created_by' => $creator->id,
            'expires_at' => now()->addDays(self::VALIDADE_EM_DIAS),
        ]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
