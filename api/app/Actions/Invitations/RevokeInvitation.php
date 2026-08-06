<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use Illuminate\Validation\ValidationException;

class RevokeInvitation
{
    /**
     * @throws ValidationException
     */
    public function handle(Invitation $invitation): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Este convite já foi utilizado e não pode ser revogado.',
            ]);
        }

        $invitation->revoked_at = now();
        $invitation->save();

        return $invitation;
    }
}
