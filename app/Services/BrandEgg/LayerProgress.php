<?php

declare(strict_types=1);

namespace App\Services\BrandEgg;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Enums\LayerItemState;
use App\Models\BrandEggMessage;
use App\Models\Client;

/**
 * The checklist for one layer of one brand's Brand Egg.
 *
 * THE ONE CLASS THAT DECIDES A TICK. The screen, the assistant's turn and any
 * test all read it from here, for the same reason BrandEggLayer::sources() is
 * the single list of what feeds a layer: three callers each counting for
 * themselves would eventually disagree, and a checklist that disagrees with the
 * database is worse than no checklist.
 *
 * ⚠️ THE MODEL NEVER WRITES THIS LIST. The assistant's prompt is given the
 * rendered checklist and writes the sentence underneath it; it is not asked to
 * keep a tally. A language model keeping score is right most of the time, and
 * a wrongly ticked entregable is a small lie about whether the brand's promise
 * exists — the one kind of error this app is built to make impossible.
 *
 * ⚠️ NOTHING HERE IS STORED. Every state is derived at read time, the same rule
 * as BrandEggState: Filled from the column, Skipped from the conversation,
 * Pending from neither. See LayerItemState.
 *
 * @see docs/brand-egg.md §14.3
 */
final readonly class LayerProgress
{
    /** @param array<int, LayerItem> $items */
    private function __construct(
        public BrandEggLayer $layer,
        public array $items,
    ) {}

    /**
     * Read a layer's checklist for this brand.
     *
     * ⚠️ ONE QUERY FOR THE WHOLE THREAD, not one per optional entregable. The
     * skipped reading needs to know what has been asked about, and asking that
     * per item would be six queries to draw six lines.
     */
    public static function for(Client $client, BrandEggLayer $layer): self
    {
        $deliverables = $client->deliverablesOrNew();
        $asked = self::askedOn($client);

        $items = [];

        foreach ($layer->sources() as $item) {
            $filled = $deliverables->has($item);

            $items[] = new LayerItem(
                item: $item,
                state: match (true) {
                    $filled => LayerItemState::Filled,
                    // Only an OPTIONAL can be skipped. A required entregable
                    // nobody answered stays pending however often it was
                    // raised — declining it is not a thing the team may do,
                    // and letting a question close it would let the assistant
                    // talk a layer into looking finished.
                    ! $item->isRequired() && in_array($item, $asked, true) => LayerItemState::Skipped,
                    default => LayerItemState::Pending,
                },
                filledEarlierIn: $filled ? self::earlierLayerFor($item, $layer) : null,
            );
        }

        return new self($layer, $items);
    }

    /**
     * What the assistant should ask about next, or null when the layer is done.
     *
     * Required first, in the order sources() lists them — which is not
     * alphabetical and not arbitrary: the relato comes first in layer 1 because
     * the other five lean on it. Optionals only once the obligatorios are in,
     * so a team is never asked for a Manifesto while the Brand promise is still
     * blank.
     */
    public function next(): ?DeliverableItem
    {
        foreach ([true, false] as $required) {
            foreach ($this->items as $line) {
                if ($line->isPending() && $line->item->isRequired() === $required) {
                    return $line->item;
                }
            }
        }

        return null;
    }

    /** Nothing left pending — every entregable is either filled or declined. */
    public function isSettled(): bool
    {
        return $this->next() === null;
    }

    /**
     * Whether the layer can be composed yet.
     *
     * ⚠️ LOOSER THAN isSettled(), on purpose. §2 Finding 3 already rules that a
     * layer built on thin inputs says what it is missing rather than inventing
     * it, and that a layer with NO sources at all is not generated. So having
     * every obligatorio is enough to write something honest; waiting for the
     * optionals would leave layers uncomposed over a Manifesto the brand is
     * never going to have.
     */
    public function isComposable(): bool
    {
        foreach ($this->items as $line) {
            if ($line->item->isRequired() && $line->isPending()) {
                return false;
            }
        }

        return true;
    }

    public function filledCount(): int
    {
        return count(array_filter(
            $this->items,
            static fn (LayerItem $line) => $line->state === LayerItemState::Filled,
        ));
    }

    /**
     * The checklist as the markdown the turn carries.
     *
     * ⚠️ THIS GOES IN THE USER TURN, NEVER ABOVE IT. It changes the moment
     * anybody accepts a card, so it is the most volatile thing in the request —
     * in the cacheable prefix it would give every turn its own prefix and make
     * each one ~150x dearer (CLAUDE.md §7).
     */
    public function toMarkdown(): string
    {
        $lines = array_map(
            static fn (LayerItem $line): string => '- '.$line->line(),
            $this->items,
        );

        return implode("\n", $lines);
    }

    /**
     * The entregables this brand's Egg conversation has raised.
     *
     * ⚠️ ACROSS EVERY LAYER, not just this one. An optional declined while
     * building the yolk is declined — `manifesto` feeds layers 1 and 5, and
     * being asked about it again on the outer ring because the first refusal
     * was filed under a different layer is exactly the repetition the checklist
     * exists to prevent.
     *
     * @return array<int, DeliverableItem>
     */
    private static function askedOn(Client $client): array
    {
        $asked = [];

        foreach (BrandEggMessage::where('client_id', $client->id)->get() as $turn) {
            foreach ($turn->askedItems() as $item) {
                $asked[$item->value] = $item;
            }
        }

        return array_values($asked);
    }

    /**
     * The first layer before this one that also reads this entregable.
     *
     * @see LayerItem::$filledEarlierIn for why the caller needs it.
     */
    private static function earlierLayerFor(DeliverableItem $item, BrandEggLayer $layer): ?BrandEggLayer
    {
        foreach (BrandEggLayer::cases() as $candidate) {
            if ($candidate->ring() >= $layer->ring()) {
                continue;
            }

            if (in_array($item, $candidate->sources(), true)) {
                return $candidate;
            }
        }

        return null;
    }
}
