<?php

namespace App\Enums;

enum HouseholdRole: string
{
    /** Acesso total à casa: membros, convites, e todo o conteúdo. */
    case Admin = 'admin';

    /** Visualiza e interage com o conteúdo; não gerencia pessoas. */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Member => 'Membro',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
