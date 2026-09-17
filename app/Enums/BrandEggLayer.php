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
            /*
             * Layer 4 — "Brand Assets / Icons" — READS NO ENTREGABLE AT ALL,
             * and the empty array is the decision rather than an oversight.
             *
             * ⚠️ IT HAD ELEVEN AND THEY WERE REMOVED ON 2026-09-17. Two of them
             * came from the brief (Look and feel, Relato); nine visual ones
             * were added on 2026-09-16 on the reasoning that Breakfast writes
             * its visual definitions down as entregables, so the layer should
             * not have to wait on image understanding to hold anything. Both
             * moves were wrong for the same reason, and Breakfast said so:
             *
             * **THE EGG IS TIER 1.** Deriving the list of a brand's assets from
             * the entregables would put tier 2 above tier 1 on the one layer
             * where the Egg is meant to BE the source. And it cannot work
             * anyway: nobody knows in advance what assets a brand will have, so
             * a fixed list of eleven entregables cannot describe them.
             *
             * What layer 4 is: **a list of assets and their type**, curated by a
             * person out of `brand_assets`, held as row ids in
             * `brand_egg_assets`. Type, title, description and URL are read off
             * those rows at render time (BrandEgg::inventoryMarkdown()).
             *
             * ⚠️ AND IT IS WHAT MAKES A FILE A BRAND ASSET. `brand_assets` is
             * every file we hold for a brand — uploads, references, things
             * somebody pasted at an assistant. Being in that table means
             * nothing; being in the Egg's inventory means a person decided this
             * one IS the brand's.
             *
             * The nine entregables are not lost and nothing about the board
             * changes. They live in tier 2 where Brandy already reads them, and
             * they hold a different thing: the RULE about an asset — "el
             * identificativo principal se usa sobre fondo claro" — whose text
             * may contain a link (CLAUDE.md §8 rule 1). Prose about an asset in
             * tier 2; the asset itself in tier 1. No overlap in meaning even
             * where both name the same PNG.
             *
             * @see docs/brand-egg.md §14.8
             */
            self::Assets => [],
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
     * Whether this layer is an INVENTORY rather than a paragraph.
     *
     * ⚠️ EXACTLY ONE LAYER IS, and it changes what the layer physically is.
     * The other four hold text a composer wrote; this one holds a list of rows
     * in `brand_assets`, and its description and URL are fetched from there
     * when something asks. So it is never composed, never approved as prose,
     * and correcting a file's description corrects the Egg with nothing to
     * re-run.
     *
     * "Brand Assets / Icons" is not a claim about the brand — it is a list of
     * things that exist. Written as prose it could describe a logo but never
     * point at one.
     */
    public function isInventory(): bool
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

    /**
     * The layers that are TEXT, and therefore have a column on `brand_eggs`.
     *
     * ⚠️ NOT every case. The inventory layer holds rows in a pivot, not a
     * column, so including it here would create a column nothing writes and
     * give the Egg two places claiming to hold layer 4.
     *
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return array_values(array_map(
            fn (self $layer) => $layer->value,
            array_filter(self::cases(), fn (self $layer) => ! $layer->isInventory()),
        ));
    }

    /**
     * The layers a composer can write. Same set as columns(), said as cases.
     *
     * @return array<int, self>
     */
    public static function composable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $layer) => ! $layer->isInventory(),
        ));
    }
}
