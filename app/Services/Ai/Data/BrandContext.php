<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Services\Ai\Exceptions\BrandContextTooLarge;

/**
 * A client's brand definition, ready to be turned into a prompt block.
 *
 * DELIBERATELY DECOUPLED from Eloquent. This layer never imports the Client
 * model, so schema changes cannot break the AI layer. BrandContextRepository
 * is the one place that reads the database and builds one of these.

 * "Documents" is now a shape rather than a source: the blocks are the 48
 * entregables, the brand's ficha and the process, all written by people. There
 * were uploaded files here once — see the repository for why they went.
 *
 * Ordering matters more than it looks. DeepSeek's context cache is a pure
 * prefix match, so this object must serialise identically between requests for
 * the same client or every request pays full input price. Documents are sorted
 * by key before rendering for exactly that reason — do not "optimise" it away.
 */
final readonly class BrandContext
{
    /**
     * @param  array<string, string>  $documents  title => markdown body
     */
    private function __construct(
        public int $clientId,
        public string $clientName,
        public array $documents,
        public ?string $version = null,
    ) {}

    /**
     * @param  array<string, string>  $documents  title => markdown body
     *
     * @throws BrandContextTooLarge
     */
    public static function make(
        int $clientId,
        string $clientName,
        array $documents,
        ?string $version = null,
    ): self {
        // Sort by title so map ordering never leaks into the prompt bytes.
        ksort($documents);

        $context = new self($clientId, $clientName, $documents, $version);

        $max = (int) config('ai.context.max_characters');

        if ($max > 0 && $context->characterCount() > $max) {
            throw new BrandContextTooLarge(
                "Brand context for client {$clientId} is {$context->characterCount()} characters, "
                ."over the {$max} limit. Trim the context documents rather than letting the "
                .'model receive a partial brand definition.'
            );
        }

        return $context;
    }

    public function isEmpty(): bool
    {
        return $this->documents === []
            || trim(implode('', $this->documents)) === '';
    }

    public function characterCount(): int
    {
        return array_sum(array_map('mb_strlen', $this->documents));
    }

    /**
     * Renders the cacheable brand-context prompt block.
     *
     * Must contain NOTHING volatile — no timestamps, no user names, no request
     * ids. Anything that changes per request belongs in the user turn, after
     * this block. See BrandContextBuilder.
     */
    public function toPrompt(): string
    {
        $parts = ["# Contexto de marca: {$this->clientName}"];

        if ($this->version !== null) {
            $parts[] = "Versión del contexto: {$this->version}";
        }

        foreach ($this->documents as $title => $body) {
            $parts[] = "## {$title}\n\n".trim($body);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Stable fingerprint of the context. Store alongside a generation so you
     * can tell which version of the brand definition produced which output,
     * and use it as a cache key for anything derived from the context.
     */
    public function fingerprint(): string
    {
        return hash('xxh128', $this->toPrompt());
    }
}
