<?php

namespace App\Enums;

/**
 * A page of the client portal.
 *
 * The case value is both the URL segment (/portal/estrategia) and the key used
 * in User::$permissions, so renaming one is a data migration, not a rename.
 *
 * ⚠️ EVERY SECTION HERE IS A PAGE THAT EXISTS. Eight were removed across
 * 2026-08-14 — Entregas, Contenido, Cronopost, Reportes, IA Studio,
 * Suscripción, Proyecto and Ayuda. The first six were stubs that filled the
 * sidebar with "esta sección se construye en la siguiente fase"; Proyecto had
 * a real page whose three-step bar the dashboard already shows, and Ayuda
 * never got written. Adding one back means building its page in the same pass;
 * Suscripción in particular comes back with the billing work, planned in
 * docs/suscripciones.md. Old slugs left in users.permissions are ignored: the
 * maps are read by walking these cases.
 *
 * ⚠️ NO GROUPS IN THE SIDEBAR. The nav is a flat list — with five entries,
 * headings over them were furniture rather than navigation, so group() and
 * grouped() went with them. The three groups below are still real, but they
 * are about PERMISSION, not layout:
 *
 *   grantable   The owner picks which of these a member gets.
 *   owner-only  Never delegated. Managing the team stays with the brand owner
 *               — see UserRole::ClienteOwner. Billing will join it here.
 *   always-on   Everyone in the brand gets these. A portal you cannot change
 *               your own password on is broken, not locked down.
 */
enum PortalSection: string
{
    // --- grantable ---------------------------------------------------------
    case Estrategia = 'estrategia';
    case Reuniones = 'reuniones';
    case BrandAssets = 'brand-assets';

    // --- owner-only --------------------------------------------------------
    case Equipo = 'equipo';

    // --- always-on ---------------------------------------------------------
    case Perfil = 'perfil';

    public function label(): string
    {
        return match ($this) {
            // 'estrategia' as a value, "Tu marca" as a label: the page has
            // always called itself that, and the client is reading their own
            // brand, not a document category. Value unchanged — it is the URL
            // and the permissions key.
            self::Estrategia => 'Tu marca',
            self::Reuniones => 'Reuniones',
            // The case value stays 'brand-assets': it is the URL segment and
            // the key in users.permissions, so renaming it is a data
            // migration. This is the label, and only the label.
            self::BrandAssets => 'Archivos',
            self::Equipo => 'Equipo',
            self::Perfil => 'Perfil',
        };
    }

    /** One line for the permission grid, so the owner knows what they hand over. */
    public function description(): string
    {
        return match ($this) {
            self::Estrategia => 'El documento de estrategia de la marca.',
            self::Reuniones => 'Agenda, notas y grabaciones.',
            self::BrandAssets => 'Logos, tipografías y paleta.',
            self::Equipo => 'Invita y administra a tu equipo.',
            self::Perfil => 'Datos y contraseña de la cuenta.',
        };
    }

    /** Tabler icon name, rendered as <x-tabler-{icon}>. */
    public function icon(): string
    {
        return match ($this) {
            self::Estrategia => 'bulb',
            self::Reuniones => 'video',
            self::BrandAssets => 'palette',
            self::Equipo => 'users',
            self::Perfil => 'user',
        };
    }

    public function routeName(): string
    {
        return 'portal.'.str_replace('-', '_', $this->value);
    }

    /* ---------------------------------------------------------------------
     | Groups
     --------------------------------------------------------------------- */

    /** The owner chooses a level for these, per member. */
    public function isGrantable(): bool
    {
        return ! $this->isOwnerOnly() && ! $this->isAlwaysOn();
    }

    /**
     * Reserved to the brand owner. Never appears as a checkbox.
     *
     * One section today, and still its own group rather than a check against
     * Equipo scattered about: Suscripción was here until billing was deferred
     * and will be again, so the question stays "is this the owner's alone?"
     * rather than "is this Equipo?".
     */
    public function isOwnerOnly(): bool
    {
        return in_array($this, [self::Equipo], true);
    }

    /** Everyone in the brand has these, at the level baselineLevel() gives. */
    public function isAlwaysOn(): bool
    {
        return in_array($this, [self::Perfil], true);
    }

    /**
     * What a member gets without anyone granting it.
     *
     * Perfil is Write because it is their own account.
     */
    public function baselineLevel(): ?AccessLevel
    {
        return match ($this) {
            self::Perfil => AccessLevel::Write,
            default => null,
        };
    }

    /** @return array<int, self> The three the owner can hand out, in nav order. */
    public static function grantable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isGrantable()));
    }
}
