<?php

namespace App\Enums;

/**
 * Who someone is in the system.
 *
 * The two Breakfast-side roles have no client_id. The two client-side roles
 * must have one — see App\Models\User::isBreakfast().
 *
 * Note: the architecture doc specced spatie/laravel-permission. We're using a
 * native enum instead while the permission model is this simple (four roles,
 * no granular permissions). Swap to spatie when roles need per-permission
 * granularity; the call sites all go through User::can* helpers and policies,
 * so the change stays contained.
 */
enum UserRole: string
{
    /** Breakfast — full access, including billing and back-office. */
    case Admin = 'admin';

    /** Breakfast — day-to-day team, no financial back-office. */
    case Equipo = 'equipo';

    /** Client — brand owner. Sees billing, can invite teammates. */
    case ClienteOwner = 'cliente_owner';

    /** Client — uses the portal and IA Studio. No billing. */
    case ClienteMiembro = 'cliente_miembro';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Equipo => 'Equipo',
            self::ClienteOwner => 'Dueño de marca',
            self::ClienteMiembro => 'Miembro',
        };
    }

    /** True for roles that work at Breakfast rather than for a client. */
    public function isBreakfast(): bool
    {
        return in_array($this, [self::Admin, self::Equipo], true);
    }

    /** Roles that may be assigned to a user belonging to a client. */
    public static function clientRoles(): array
    {
        return [self::ClienteOwner, self::ClienteMiembro];
    }
}
