<?php

declare(strict_types=1);

namespace App\Services\Ai\Brand;

use App\Enums\DeliverableItem;

/**
 * The 48 entregables, rendered for a language model.
 *
 * App\Enums\DeliverableItem is still the only definition of what Breakfast
 * delivers. This class does not add entregables, reorder them or reword them —
 * it serialises them, so the board the team fills by hand and the taxonomy the
 * assistant extracts against can never drift apart.
 *
 * Two renderings, for two different jobs:
 *
 *   · toArray()/toJson() — the machine-readable export, for the client and for
 *     eval fixtures.
 *   · promptBlock()      — the compact text that goes in the system prompt.
 *     JSON would cost roughly twice the tokens to say the same thing, and this
 *     block is repeated on every extraction request.
 *
 * Flat, with no grouping. The taxonomy has exactly one classification —
 * obligatorio or not — and inventing blocks to organise the prompt would put
 * categories in the model's head that exist nowhere else in the system.
 */
final class DeliverableSchema
{
    /**
     * Every entregable as a plain array, in board order.
     *
     * @return array<int, array{key: string, required: bool, label: string, hint: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (DeliverableItem $item): array => [
            'key' => $item->value,
            'required' => $item->isRequired(),
            'label' => $item->label(),
            'hint' => $item->hint(),
        ], DeliverableItem::cases());
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The schema as it appears inside the system prompt.
     *
     * One line per entregable: key, whether it is required, label, then the
     * hint that teaches the shape the answer should take. The hints are doing
     * real work — they are the difference between "Colores: azul y crema" and
     * a list of hexes with roles, which is the only version the assistant can
     * cite later.
     */
    public function promptBlock(): string
    {
        $lines = [];

        foreach (DeliverableItem::cases() as $item) {
            $level = $item->isRequired() ? 'obligatorio' : 'opcional';
            $line = "- {$item->value} [{$level}] {$item->label()}";

            if ($item->hint() !== '') {
                $line .= " — {$item->hint()}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Valid destination keys, for validating whatever the model sends back.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return DeliverableItem::columns();
    }
}
