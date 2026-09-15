<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * One turn of the onboarding assistant.
 *
 * The assistant does three things at once and all of them come back in a
 * single call: it talks to the person (`reply`, `questions`), it proposes
 * entregable content (`proposals`), and it proposes the brand's own details
 * (`brand`). Splitting those into separate requests would pay to read the same
 * brandbook more than once and let the halves disagree about what it said.
 *
 * `brand` is separate from `proposals` because it targets a different table.
 * The 48 entregables are what the brand IS, on brand_deliverables; name,
 * industry and contact are how Breakfast FILES the client, on clients. A
 * brandbook usually states both, and before this existed the assistant could
 * read a brand's name off page one and had no key to return it under.
 */
final readonly class ExtractionResult
{
    /**
     * @param  array<int, DeliverableProposal>  $proposals
     * @param  array<int, string>  $questions
     * @param  array<string, string>  $brand  Whitelisted clients-table fields.
     */
    public function __construct(
        public string $reply,
        public array $proposals,
        public array $questions,
        public LlmResponse $response,
        public array $brand = [],
    ) {}

    public function hasProposals(): bool
    {
        return $this->proposals !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'proposals' => array_map(fn (DeliverableProposal $p) => $p->toArray(), $this->proposals),
            'questions' => $this->questions,
            'brand' => $this->brand,
        ];
    }
}
