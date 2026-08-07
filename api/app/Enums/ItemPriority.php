<?php

namespace App\Enums;

enum ItemPriority: string
{
    case Low = 'baixa';
    case Normal = 'normal';
    case High = 'alta';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixa',
            self::Normal => 'Normal',
            self::High => 'Alta',
        };
    }
}
