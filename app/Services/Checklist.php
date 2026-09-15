<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeliverableItem;
use App\Models\BrandDeliverables;

/**
 * The implementation checklist, as items a person can tick — SEG-05.
 *
 * THE ONE PLACE THAT DECIDES WHAT AN ITEM IS. The checklist is stored as one of
 * the 48 entregables: a block of text Breakfast types into a textarea. Turning
 * that into tickable lines is a reading of the text, and every screen that
 * shows the checklist — the client's, the admin's — reads it through here, so
 * the two cannot disagree about how many items there are or which is which.
 *
 * ⚠️ THE KEY IS THE LINE, NOT ITS POSITION. Ticks are stored against a hash of
 * the item's normalised text, which decides the two behaviours that matter:
 *
 *   · reorder the list and every tick survives — same lines, same keys
 *   · edit a line and its tick is gone — a rewritten item is a different item,
 *     and moving the tick across would claim somebody confirmed a sentence they
 *     have never read
 *
 * The normalisation is deliberately loose about decoration and strict about
 * words: fixing "  - Publicar el manual" to "- Publicar el manual." must not
 * silently untick it, but changing "el manual" to "el brandbook" must.
 */
final class Checklist
{
    /**
     * @param  array<int, ChecklistItem>  $items
     */
    private function __construct(public readonly array $items) {}

    /** Read a brand's checklist entregable. */
    public static function for(BrandDeliverables $deliverables): self
    {
        return self::fromText(
            (string) $deliverables->value(DeliverableItem::ChecklistImplementacion)
        );
    }

    public static function fromText(string $text): self
    {
        $items = [];
        $seen = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $label = self::strip($line);

            if ($label === '') {
                continue;
            }

            $key = self::key($label);

            // The same sentence twice is one item: two checkboxes sharing a key
            // would tick and untick each other, which reads as the page
            // fighting the person using it.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = new ChecklistItem($key, $label);
        }

        return new self($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<int, string> Every key currently in the list. */
    public function keys(): array
    {
        return array_map(static fn (ChecklistItem $item): string => $item->key, $this->items);
    }

    /**
     * A line with its bullet or number taken off.
     *
     * Breakfast writes these however they write them — "- Publicar", "1. Publicar",
     * "• Publicar", or just "Publicar". All four are one item, and the marker is
     * decoration rather than content.
     */
    private static function strip(string $line): string
    {
        $clean = trim($line);
        $clean = preg_replace('/^\s*(?:[-*•–—]|\d+[.)])\s*/u', '', $clean) ?? $clean;

        // A markdown checkbox, if somebody typed one. The state in the text is
        // NOT read as a tick: what is done lives in checklist_ticks, and taking
        // it from the text as well would be two truths about the same thing.
        $clean = preg_replace('/^\[\s*[xX]?\s*\]\s*/u', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * The stable identity of an item.
     *
     * Case, accents, punctuation and repeated spaces are stripped before
     * hashing, so tidying a line does not untick it. The WORDS are what
     * identify the item — change those and it is a new item, which is the whole
     * point.
     */
    public static function key(string $label): string
    {
        $plain = mb_strtolower(trim($label));

        $plain = strtr($plain, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $plain = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $plain) ?? $plain;

        return sha1(trim($plain));
    }
}
