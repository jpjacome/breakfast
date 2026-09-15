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

    /** Roles that may be assigned to someone who works at Breakfast. */
    public static function breakfastRoles(): array
    {
        return [self::Admin, self::Equipo];
    }

    /** What this role is for, in one line, next to the picker. */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Todo el back-office, incluida la facturación y este equipo.',
            self::Equipo => 'Clientes, marcas y contexto. No toca facturación ni el equipo.',
            self::ClienteOwner => 'Dueño de su marca: ve la suscripción e invita a su gente.',
            self::ClienteMiembro => 'Usa el portal de su marca con los permisos que le den.',
        };
    }
}
