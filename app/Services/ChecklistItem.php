<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One line of a brand's implementation checklist — SEG-05.
 *
 * Deliberately dumb: whether it is ticked is NOT stored here, because that
 * belongs to a brand and this belongs to the text Breakfast wrote. The screens
 * pair the two. See App\Services\Checklist, which is the only thing that
 * builds these.
 */
final class ChecklistItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}
}
