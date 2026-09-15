<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * What came back from reading a brand's documents, before anything is proposed.
 *
 * The read phase has one job: turn files into text this app can keep. It does
 * NOT propose entregables — that happens afterwards, in several short calls
 * over the digest, because doing both in one generation is what exceeded the
 * host's request ceiling and took the public site down with it.
 *
 * `brand` still rides along because a brandbook states the brand's name and
 * industry on page one, and a reading is a reading whichever phase found it.
 */
final class DocumentReading
{
    /**
     * @param  string  $reply  what to say in the thread
     * @param  string  $digest  what the documents SAY, as text worth keeping
     * @param  string  $visual  what they LOOK like — see the constructor
     * @param  array<int, string>  $questions
     * @param  array<string, string>  $brand  name/industry/contact, when stated
     */
    public function __construct(
        public readonly string $reply,
        public readonly string $digest,
        /**
         * What the material looks like, in words.
         *
         * Separate from $digest because they are different kinds of claim and
         * only one of them may become brand data. The digest is what the brand
         * SAYS about itself — quotable, literal. This is a READING of how the
         * pages look, and the extractor's rule stands untouched over it:
         * describing a page is allowed, deciding that what you see IS the
         * brand's typeface is not (see onboarding_prompt, "Leer no es
         * reconocer").
         *
         * Kept because the files do not travel twice. Without it, "¿cómo se ve
         * el toolkit?" a week later has nothing to answer from but a filename.
         */
        public readonly string $visual = '',
        public readonly array $questions = [],
        public readonly array $brand = [],
        public readonly ?LlmResponse $response = null,
    ) {}

    /**
     * Nothing came out of the files worth keeping.
     *
     * ⚠️ BOTH HALVES, not just the digest. A page of pure graphics — a Look and
     * Feel spread, an illustration, a colour chart — has no text to quote, so
     * the digest comes back empty while the visual reading is the only thing
     * the file had to give. Asking the digest alone threw that away, silently,
     * for precisely the material this was built to capture. Found against the
     * real provider: an image returned an empty digest and a good description.
     */
    public function isEmpty(): bool
    {
        return trim($this->digest) === '' && trim($this->visual) === '';
    }
}
