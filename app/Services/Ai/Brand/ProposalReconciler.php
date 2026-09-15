<?php

declare(strict_types=1);

namespace App\Services\Ai\Brand;

use App\Models\BrandDeliverables;
use App\Services\Ai\Data\DeliverableProposal;

/**
 * Pairs each proposal with what the form currently says, and drops the no-ops.
 *
 * It used to decide whether a proposal could be applied unattended, weighing
 * confidence against a threshold. That decision is gone: EVERY proposal is now
 * a card a person applies. Nothing the assistant produces reaches a textarea
 * without a click, which is the reason brand_deliverables has no provenance
 * column — there is no state where the model authored a value alone.
 *
 * What is left is the part that still needs deciding: whether a proposal says
 * anything new. Without that, re-reading the same brandbook queues twenty
 * identical "replace X with X" cards and the person stops reading them.
 *
 * The state compared against is the state of the FORM, not of the saved row —
 * somebody who typed two minutes ago and has not pressed Guardar yet still
 * owns that entregable. The controller passes the current form values in for
 * exactly that reason.
 */
final class ProposalReconciler
{
    /**
     * @param  array<int, DeliverableProposal>  $proposals
     * @param  array<string, string>  $formValues  Unsaved form state, key => value.
     * @return array<int, array<string, mixed>>
     */
    public function reconcile(
        BrandDeliverables $deliverables,
        array $proposals,
        array $formValues = [],
    ): array {
        $cards = [];

        foreach ($proposals as $proposal) {
            $key = $proposal->item->value;

            // The form wins over the row whenever it has an opinion, including
            // the opinion that the entregable is empty because somebody
            // cleared it.
            $current = array_key_exists($key, $formValues)
                ? trim($formValues[$key])
                : $deliverables->value($proposal->item);

            // Whitespace and case are not a disagreement worth reviewing.
            if ($this->sameText($current, $proposal->value)) {
                continue;
            }

            $cards[] = [
                ...$proposal->toArray(),
                'current' => $current,
            ];
        }

        // Least certain first: the readings that most need a person's eyes are
        // the ones that should not be at the bottom of a list of forty.
        usort($cards, fn (array $a, array $b) => $a['confidence'] <=> $b['confidence']);

        return $cards;
    }

    private function sameText(string $a, string $b): bool
    {
        $normalise = static fn (string $text): string => mb_strtolower(
            (string) preg_replace('/\s+/u', ' ', trim($text))
        );

        return $normalise($a) === $normalise($b);
    }
}
