<?php

namespace App\Enums;

/**
 * What a file in a brand's folder IS.
 *
 * ⚠️ THE INVENTORY'S MISSING QUESTION. `brand_assets` has always known where a
 * file lives, who may see it and how it got there — but not what it is. A logo,
 * a colour sheet and a signed contract were three rows distinguishable only by
 * whatever somebody typed in the title. So "show me the logo" had no answer a
 * query could give, and the Brand Egg's fourth layer had no way to point at the
 * primary identificativo rather than at "some file".
 *
 * ⚠️ A THIRD QUESTION, NOT A THIRD KIND OF FILE. This sits beside
 * AssetVisibility (who may see it) and AssetSource (how it arrived), and it
 * must stay as separate from them as they are from each other. Splitting the
 * folder by type would repeat exactly the mistake that killed `context_documents`
 * — dividing files by what they ARE put them on screens that hid each other.
 * One list, three independent labels.
 *
 * ⚠️ NULLABLE ON PURPOSE, AND IT MEANS "NOBODY HAS SAID". Not `Otro`. A file
 * uploaded before this existed, or one nobody has classified yet, is genuinely
 * unknown — and an unknown that defaults to "other" can never be told apart
 * from one a person looked at and decided was miscellaneous. The first is work
 * outstanding; the second is a finished decision.
 */
enum AssetType: string
{
    /* --- the identity itself ------------------------------------------- */

    case Logo = 'logo';
    case LogoSecundario = 'logo_secundario';
    case Isotipo = 'isotipo';

    /* --- the system around it ------------------------------------------ */

    case Paleta = 'paleta';
    case Tipografia = 'tipografia';
    case Ilustracion = 'ilustracion';
    case Personaje = 'personaje';
    case Patron = 'patron';

    /* --- things that are not the identity ------------------------------ */

    case Fotografia = 'fotografia';
    case Aplicacion = 'aplicacion';
    case Documento = 'documento';
    case Audio = 'audio';
    case Video = 'video';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'Logo principal',
            self::LogoSecundario => 'Logo secundario',
            self::Isotipo => 'Isotipo',
            self::Paleta => 'Paleta de color',
            self::Tipografia => 'Tipografía',
            self::Ilustracion => 'Ilustración',
            self::Personaje => 'Personaje',
            self::Patron => 'Patrón / textura',
            self::Fotografia => 'Fotografía',
            self::Aplicacion => 'Aplicación',
            self::Documento => 'Documento',
            self::Audio => 'Audio',
            self::Video => 'Video',
            self::Otro => 'Otro',
        };
    }

    /**
     * Whether this type belongs to the brand's visual identity.
     *
     * What layer 4 of the Brand Egg is about. A contract and a photo both live
     * in the same folder as the logo, and neither says anything about how the
     * brand looks — so the Egg reads the ones that do and leaves the rest.
     */
    public function isIdentity(): bool
    {
        return match ($this) {
            self::Logo, self::LogoSecundario, self::Isotipo,
            self::Paleta, self::Tipografia, self::Ilustracion,
            self::Personaje, self::Patron => true,
            default => false,
        };
    }

    /**
     * The one file that IS the brand's mark, when there is one.
     *
     * Kept as a method rather than as a lone `Logo` check so that the day
     * somebody asks "which is the main identificativo" there is one answer and
     * one place it is decided.
     */
    public function isPrimaryMark(): bool
    {
        return $this === self::Logo;
    }

    public function badgeClass(): string
    {
        return $this->isIdentity() ? 'admin-badge' : 'admin-badge admin-badge-quiet';
    }

    /**
     * The types offered for a file, in the order a person would look for them.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return self::cases();
    }
}
