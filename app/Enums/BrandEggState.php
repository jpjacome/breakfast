<?php

namespace App\Enums;

/**
 * Where a brand's Brand Egg stands — asked ONCE PER BRAND, never per layer.
 *
 * ⚠️ THIS IS NOT THE STATUS COLUMN CLAUDE.md §8 RULE 2 FORBIDS. That rule
 * governs a single entregable: filled is done, empty is pending, and a second
 * truth beside the text is one somebody has to remember to move. Whether
 * Breakfast has SIGNED OFF the Egg is a different question — it is about a
 * person's act, not about content, so no content can contradict it. It is
 * asked once for the whole Egg and there is nothing per-field to drift.
 *
 * ⚠️ ONLY TWO OF THE FOUR ARE STORED, AND NEITHER OF THEM IS A STATE NAME.
 * brand_eggs carries generated_at and approved_at — two timestamps, both
 * written by the act they record. Every case below is DERIVED from them plus
 * deliverables.updated_at at read time. In particular:
 *
 *   ⚠️ DESACTUALIZADO IS NEVER WRITTEN ANYWHERE. It is a comparison, not a
 *   flag. A flag would have to be flipped by whoever edits an entregable —
 *   every screen that writes one, forever — so it would be wrong the first
 *   time somebody added a route. Deriving it is also what CLAUDE.md §3 asks
 *   for: never let correctness depend on a write somebody must remember.
 *
 * @see docs/brand-egg.md §5 — the three states, all derived.
 */
enum BrandEggState: string
{
    /** No layer has ever been composed. generated_at is null. */
    case SinGenerar = 'sin_generar';

    /** Composed, and nobody has signed it off yet. */
    case SinAprobar = 'sin_aprobar';

    /** Approved, and no entregable has moved since. */
    case Aprobado = 'aprobado';

    /** Approved, then an entregable it was composed from was edited. */
    case Desactualizado = 'desactualizado';

    public function label(): string
    {
        return match ($this) {
            self::SinGenerar => 'Sin generar',
            self::SinAprobar => 'Sin aprobar',
            self::Aprobado => 'Aprobado',
            self::Desactualizado => 'Desactualizado',
        };
    }

    /**
     * What the state means, in the words the screen uses beside the drawing.
     *
     * ⚠️ DESACTUALIZADO IS NOT PHRASED AS A FAULT. The entregables moving is
     * the app working — Breakfast edited a brand — and the Egg being behind is
     * the expected consequence, not somebody's oversight. Same register as
     * ERR-07 of the beta review, which is why "no forma parte" replaced
     * "nos falta".
     */
    public function description(): string
    {
        return match ($this) {
            self::SinGenerar => 'Todavía no se ha compuesto ninguna capa.',
            self::SinAprobar => 'Compuesto. Falta que Breakfast lo apruebe para que la marca lo vea.',
            self::Aprobado => 'Aprobado y al día con los entregables.',
            self::Desactualizado => 'Se editaron entregables después de aprobarlo. Conviene recomponer las capas afectadas y volver a aprobar.',
        };
    }

    /**
     * admin.css badge modifier, or '' for the neutral badge.
     *
     * Sin generar is deliberately unmodified: an Egg nobody has composed is
     * not a warning, it is the state every brand starts in. Desactualizado is
     * the only one that asks for anything to be done, so it is the only one
     * that raises its voice.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Aprobado => 'admin-badge-success',
            self::Desactualizado => 'admin-badge-warning',
            self::SinGenerar, self::SinAprobar => '',
        };
    }

    /**
     * Whether the client may see the Egg at all.
     *
     * ⚠️ THE ONE PLACE APPROVAL GATES ANYTHING. The Egg is the single artefact
     * in this app whose whole claim is that a person signed it off, so showing
     * the brand a draft of its own essence would undo the claim. Desactualizado
     * still counts as approved: somebody DID sign it off, and the entregables
     * having moved since is a reason to recompose, not a reason to take the
     * brand's memory away from it mid-project.
     *
     * ⚠️ IT DOES NOT GATE THE ASSISTANT. An unapproved Egg is still Brandy's
     * memory — every brand is unapproved today, and gating there would leave
     * all of them with an assistant that knows less than it did. Approval
     * changes what the prompt SAYS ABOUT the Egg, not whether it is there.
     * See docs/brand-egg.md §8.
     */
    public function isVisibleToClient(): bool
    {
        return $this === self::Aprobado || $this === self::Desactualizado;
    }
}
