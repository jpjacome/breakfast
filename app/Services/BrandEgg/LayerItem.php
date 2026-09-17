<?php

declare(strict_types=1);

namespace App\Services\BrandEgg;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Enums\LayerItemState;

/**
 * One line of a layer's checklist: an entregable, where it stands, and where
 * it was filled if it was filled somewhere else first.
 *
 * @see LayerProgress, which is the only thing that builds these.
 */
final readonly class LayerItem
{
    public function __construct(
        public DeliverableItem $item,
        public LayerItemState $state,
        /**
         * The EARLIER layer that already had this entregable as a source, when
         * one does.
         *
         * ⚠️ WHAT KEEPS THE ASSISTANT FROM ASKING TWICE. Six of the entregables
         * feed more than one layer — `valores` feeds 1, 2 and 5; `relato` feeds
         * 1, 3 and 4 — so layer 3 opens with two of its four already in. Saying
         * "esos dos ya los tenemos de la yema" is the difference between this
         * reading as progress and reading as a form that repeats itself, and it
         * cannot be said without knowing WHICH layer they came from.
         *
         * Null when this is the first layer to want it, or when it is empty.
         */
        public ?BrandEggLayer $filledEarlierIn = null,
    ) {}

    public function isPending(): bool
    {
        return $this->state === LayerItemState::Pending;
    }

    /** The line as the checklist prints it: "✅ Relato de marca · de la yema". */
    public function line(): string
    {
        $line = $this->state->mark().' '.$this->item->label();

        if ($this->filledEarlierIn !== null) {
            return $line.' · ya venía de '.$this->filledEarlierIn->label();
        }

        // An empty optional says so; an empty required does not need to, since
        // pending IS the expected state of a required one nobody has reached.
        return $this->item->isRequired() || $this->state !== LayerItemState::Pending
            ? $line
            : $line.' · opcional';
    }
}
