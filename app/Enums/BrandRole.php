<?php

namespace App\Enums;

/**
 * What somebody is INSIDE ONE BRAND.
 *
 * Split out of UserRole when a person stopped belonging to a single brand
 * (ACC-01). That column answered two questions at once — which side of the app
 * you are on, and what you are within your brand — and multi-marca pulls them
 * apart: the same person can own one brand and be a member of another, which a
 * single column on the user cannot say.
 *
 * So: UserRole stays on users.role and answers "which side"; this lives on the
 * brand_user pivot and answers "what here". Neither can contradict the other,
 * because neither is asked the other's question.
 *
 * ⚠️ The values are NOT the old cliente_owner / cliente_miembro strings. The
 * old ones carried "cliente" in them because they also meant the client side;
 * on a pivot that is already true of every row, so saying it again would be
 * noise. The data migration maps them.
 */
enum BrandRole: string
{
    /** Owns the brand: builds its team, and will see its billing. */
    case Owner = 'owner';

    /** Uses the brand's portal with whatever sections they were granted. */
    case Miembro = 'miembro';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Dueño de marca',
            self::Miembro => 'Miembro',
        };
    }

    /** What this role is for, in one line, next to the picker. */
    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Dueño de esta marca: ve la suscripción e invita a su gente.',
            self::Miembro => 'Usa el portal de esta marca con los permisos que le den.',
        };
    }

    /**
     * Owner-only sections are granted by this, never by the permissions map —
     * they are what owning a brand MEANS rather than something handed out.
     * See User::accessTo() and PortalSection::isOwnerOnly().
     */
    public function owns(): bool
    {
        return $this === self::Owner;
    }
}
