<?php

namespace App\Enums;

/**
 * Where one entregable of a Brand Egg layer stands, on the assistant's
 * checklist.
 *
 * ⚠️ THREE STATES, AND THE THIRD IS THE POINT. With only Filled and Pending, a
 * brand that legitimately has no Manifesto reads "5 de 6" forever and its
 * layer can never be finished — which is ERR-07 of the beta review wearing a
 * checkbox: the app telling a team it has homework outstanding when it has
 * none. Skipped is what lets a layer close honestly.
 *
 * ⚠️ NONE OF THIS IS STORED. `brand_deliverables` has no status column and must
 * not grow one (CLAUDE.md §8 rule 2): a second truth somebody has to remember
 * to move is a second truth that can contradict the text. Every state here is
 * DERIVED at read time by LayerProgress — Filled from the column, Skipped from
 * the conversation, Pending from neither.
 *
 * @see App\Services\BrandEgg\LayerProgress
 * @see docs/brand-egg.md §14.3
 */
enum LayerItemState: string
{
    case Filled = 'filled';
    case Pending = 'pending';
    case Skipped = 'skipped';

    /** The mark the checklist prints. Presentation lives on the enum (§10). */
    public function mark(): string
    {
        return match ($this) {
            self::Filled => '✅',
            self::Pending => '⬜',
            self::Skipped => '➖',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Filled => 'Listo',
            self::Pending => 'Pendiente',
            self::Skipped => 'No aplica',
        };
    }

    /**
     * Whether this state still owes the layer something.
     *
     * Skipped counts as settled, which is the whole reason it exists — a layer
     * whose optionals were all declined is finished, not stuck at 5 de 6.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
