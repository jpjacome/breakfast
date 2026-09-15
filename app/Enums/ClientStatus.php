<?php

namespace App\Enums;

enum ClientStatus: string
{
    /**
     * Started in /clientes/nueva and not finished.
     *
     * A draft is a real row with a real id, because the moment anything is
     * written the assistant needs somewhere to put files, entregables and the
     * conversation — and every one of those already works off a client_id.
     * Holding them in the session instead would mean a second implementation
     * of all three that only exists before the Crear button.
     */
    case Borrador = 'borrador';

    case Activo = 'activo';
    case Pausado = 'pausado';
    case Cerrado = 'cerrado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Activo => 'Activo',
            self::Pausado => 'Pausado',
            self::Cerrado => 'Cerrado',
        };
    }

    /**
     * admin.css badge modifier, or '' for the neutral badge.
     *
     * Cerrado is deliberately unmodified: a closed brand is not a warning, it
     * is just a fact, and colouring it would put it in the same voice as one
     * that needs attention. Borrador is the same — unfinished is not a problem,
     * and the word is doing the work.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Activo => 'admin-badge-success',
            self::Pausado => 'admin-badge-warning',
            self::Borrador, self::Cerrado => '',
        };
    }

    /** A brand somebody is still filling in. Not yet a client of anything. */
    public function isDraft(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * The statuses a finished brand can be given.
     *
     * Borrador is not among them: it is entered by starting a brand and left
     * by finishing one, never chosen from a dropdown. Offering it there would
     * let somebody set a live brand back to "unfinished", which means nothing.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => ! $status->isDraft(),
        ));
    }
}
