<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Activo = 'activo';
    case Pausado = 'pausado';
    case Cerrado = 'cerrado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Pausado => 'Pausado',
            self::Cerrado => 'Cerrado',
        };
    }

    /** general.css badge modifier for this status. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Activo => 'bkf-badge--success',
            self::Pausado => 'bkf-badge--warning',
            self::Cerrado => 'bkf-badge',
        };
    }
}
