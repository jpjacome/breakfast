<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Enums\DeliverableItem;

/**
 * One value the assistant is proposing for one entregable.
 *
 * A proposal is not content. NOTHING the assistant produces is ever written on
 * its own: every proposal is a card a person applies into the form, and the
 * form is then saved by that same person. That is why brand_deliverables has
 * no provenance column — there is no state where the model authored a value
 * alone, so there is nothing to record.
 *
 * `evidence` is what makes review possible at all: without a pointer back to
 * where the sentence came from, checking forty proposals means re-reading the
 * brandbook forty times, and nobody does that. They just accept.
 *
 * `confidence` no longer decides anything — it only sorts review attention, so
 * the least certain readings are the ones a person looks at hardest.
 */
final readonly class DeliverableProposal
{
    public function __construct(
        public DeliverableItem $item,
        public string $value,
        public float $confidence,
        public string $evidence,
    ) {}

    /**
     * Build from one decoded element of the model's JSON, or null if it is not
     * usable. Returning null rather than throwing is deliberate: one malformed
     * proposal in a batch of twenty should cost that one proposal, not the
     * whole reply.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $item = DeliverableItem::tryFrom((string) ($raw['entregable'] ?? $raw['field'] ?? ''));
        $value = trim((string) ($raw['value'] ?? ''));

        // An unknown key means the model invented an entregable, and an empty
        // value means it had nothing to say. Neither belongs in the form.
        if ($item === null || $value === '') {
            return null;
        }

        return new self(
            item: $item,
            // The form's own cap. A model that runs long gets cut here rather
            // than at the validator, where it would fail the whole save.
            value: mb_substr($value, 0, 20000),
            confidence: self::clampConfidence($raw['confidence'] ?? null),
            evidence: mb_substr(trim((string) ($raw['evidence'] ?? '')), 0, 300),
        );
    }

    /**
     * Missing or nonsense confidence becomes 0.5, not 1.0.
     *
     * The honest default for "the model did not say" is the middle. Treating
     * silence as certainty is how an unchecked guess gets read as a fact.
     */
    private static function clampConfidence(mixed $raw): float
    {
        if (! is_numeric($raw)) {
            return 0.5;
        }

        return round(max(0.0, min(1.0, (float) $raw)), 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entregable' => $this->item->value,
            'label' => $this->item->label(),
            'value' => $this->value,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'required' => $this->item->isRequired(),
        ];
    }
}
