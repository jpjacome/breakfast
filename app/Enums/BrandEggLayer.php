<?php

namespace App\Enums;

/**
 * One of the five layers of a brand's Brand Egg.
 *
 * The Egg is not a copy of the 48 entregables and not a second template a
 * person fills by hand. Each layer is a DERIVED reading: the composer is asked
 * for the relationship between a handful of entregables and writes one
 * coherent statement out of them. Hence the metaphor — layers around a core,
 * the yolk being the essence.
 *
 * The case value is three things at once, so nothing can drift: the column
 * name on brand_eggs, the id of its <g> in the inlined SVG (brand-egg-esencia),
 * and the {layer} route segment of its update endpoint. Same argument that
 * makes DeliverableItem::Relato->value the column name — one vocabulary, no
 * mapping table.
 *
 * NOTHING OUTSIDE THIS ENUM DECIDES WHAT FEEDS A LAYER. If a screen, a prompt
 * builder and a regeneration route each carried their own list of sources they
 * would drift, and the Egg would be composed from one set of entregables and
 * explained by another. sources() lives here for that reason alone.
 *
 * @see docs/brand-egg.md §2 — the five layers and what feeds them.
 */
enum BrandEggLayer: string
{
    case Esencia = 'esencia';       // 1 · the yolk
    case Personalidad = 'personalidad';  // 2
    case Beneficios = 'beneficios';    // 3
    case Assets = 'assets';        // 4
    case Universo = 'universo';      // 5

    /**
     * The entregables a layer is synthesised from.
     *
     * Ten of the twelve inputs in the brief map onto entregables exactly.
     * Layer 4's image work and layer 5's dependency are the two that do not,
     * and they are noted where they belong instead of being smuggled in here.
     *
     * @return array<int, DeliverableItem>
     */
    public function sources(): array
    {
        return match ($this) {
            self::Esencia => [
                DeliverableItem::Relato,
                DeliverableItem::BrandPromise,
                DeliverableItem::BrandStatement,
                DeliverableItem::Manifesto,
                DeliverableItem::Valores,
                DeliverableItem::Claim,
            ],
            self::Personalidad => [
                DeliverableItem::Arquetipos,
                DeliverableItem::Valores,
            ],
            self::Beneficios => [
                DeliverableItem::BrandStatement,
                DeliverableItem::Relato,
                DeliverableItem::Insight,
                DeliverableItem::Publicos,
            ],
            /*
             * Layer 4 — "Brand Assets / Icons".
             *
             * ⚠️ THE NINE VISUAL ENTREGABLES WERE MISSING UNTIL 2026-09-16, and
             * their absence was the whole reason this ring stayed hollow. The
             * plan named "Brand Assets", which is PortalSection::BrandAssets —
             * the brand's FILES — so the layer was written against the two text
             * entregables and waited on image understanding. But Breakfast
             * writes the visual definitions down as entregables too, and those
             * are approved brand data sitting in the database, unread by
             * anything. Emblemas, Colores and the identificativos say more
             * about a brand's assets than any single file does.
             *
             * The files are not gone from this layer — they arrive as TEXT.
             * See readsAssetReadings() below.
             */
            self::Assets => [
                DeliverableItem::LookAndFeel,
                DeliverableItem::Emblemas,
                DeliverableItem::IdentificativoPrincipal,
                DeliverableItem::IdentificativoSecundario,
                DeliverableItem::BrandUniverse,
                DeliverableItem::Colores,
                DeliverableItem::Tipografia,
                DeliverableItem::Ilustraciones,
                DeliverableItem::Personaje,
                DeliverableItem::Aplicaciones,
                DeliverableItem::Relato,
            ],
            self::Universo => [
                DeliverableItem::Valores,
                DeliverableItem::Manifesto,
                DeliverableItem::BrandPromise,
                DeliverableItem::LookAndFeel,
            ],
        };
    }

    /**
     * The layer whose OUTPUT this layer reads, or null when it reads only
     * entregables.
     *
     * Layer 5 (Universo) takes "Personalidad" as an input, which is layer 2's
     * result rather than an entregable — so generation has an order: 2 before 5.
     * Every other layer is independent and may run in any order.
     *
     * @see docs/brand-egg.md §2 Finding 1 — if Breakfast meant "the same sources
     *      layer 2 reads" rather than "layer 2's result", the dependency
     *      disappears and Universo reads Arquetipos + Valores directly. Built
     *      as a dependency for now because that is the reading that produces a
     *      coherent Egg rather than two layers restating the same source.
     */
    public function dependsOn(): ?self
    {
        return match ($this) {
            self::Universo => self::Personalidad,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Esencia => 'Esencia, tagline y valores',
            self::Personalidad => 'Personalidad',
            self::Beneficios => 'Beneficios de marca',
            self::Assets => 'Brand Assets / Icons',
            self::Universo => 'Brand Universe / Emotions',
        };
    }

    /** What the layer is for, shown on hover/focus beside the label. */
    public function description(): string
    {
        return match ($this) {
            self::Esencia => 'Lo que la marca es: su esencia, su promesa y los valores que la sostienen.',
            self::Personalidad => 'Cómo se comporta y habla la marca, leída desde sus arquetipos y valores.',
            self::Beneficios => 'Lo que la marca aporta de verdad a quien la elige.',
            self::Assets => 'Los activos visuales de la marca: su look and feel y su archivo.',
            self::Universo => 'El mundo emocional que la marca abre: a dónde lleva y qué se siente ahí.',
        };
    }

    /**
     * Whether this layer also reads the descriptions of the brand's images.
     *
     * ⚠️ TEXT, NEVER PICTURES. The Egg is composed from fields of the brand and
     * nothing else (docs/brand-egg.md §1), so an image reaches a layer only
     * after DescribeBrandAsset has turned it into words stored on the asset's
     * own row. The composer sends no image, ever — it reads brand_assets.
     * visual_reading like any other column.
     *
     * ⚠️ AND ONLY LAYER 4, which is why this is a method on the enum rather
     * than something EggComposer decides. Nothing outside this class chooses
     * what feeds a layer; a second opinion living in the composer is how the
     * Egg ends up built from one set of sources and explained by another.
     */
    public function readsAssetReadings(): bool
    {
        return $this === self::Assets;
    }

    /** The ring's position, 1 being the yolk. The <g> in the SVG carries it. */
    public function ring(): int
    {
        return match ($this) {
            self::Esencia => 1,
            self::Personalidad => 2,
            self::Beneficios => 3,
            self::Assets => 4,
            self::Universo => 5,
        };
    }

    /** @return array<int, string> Every column name, for the migration and $fillable. */
    public static function columns(): array
    {
        return array_column(self::cases(), 'value');
    }
}
