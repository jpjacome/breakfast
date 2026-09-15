<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The brands a question names out loud.
 *
 * The dropdown on the dashboard was the only way to get a brand's ficha into
 * the context, which meant that in "todas las marcas" mode — the default, and
 * the mode most questions arrive in — asking "¿alea ya tiene colores
 * definidos?" was answered from the summary table alone. The table has counts,
 * not content, so the honest answer the model could give was "no tengo ese dato
 * aquí" about a brand whose colours were sitting in the database. A tester read
 * that as the assistant being unable to see the data at all.
 *
 * So the question itself is now a way of choosing a brand. Typing the name is
 * what a person does; reaching for the dropdown first is not.
 *
 * ⚠️ SCOPED, like everything else on this side. The match runs over
 * Client::visibleTo(), so naming a brand you were never put on finds nothing
 * rather than handing you its ficha — the assistant must not become the way
 * around the brand scope the screens enforce.
 *
 * ⚠️ This makes block 2 vary with the question, which costs prompt-cache hits
 * on the turns that name a brand. Accepted deliberately: a cached wrong answer
 * is worth nothing. Consecutive turns about the same brand still serialise
 * identically and still hit.
 */
final class MentionedBrands
{
    /**
     * How many fichas one question may pull in.
     *
     * A ficha carries all 48 entregables, so this is the difference between an
     * appendix and a prompt that costs real money. Two covers "¿cómo va Alea
     * contra The Coffee Club?"; past that the question is really about the
     * portfolio, which the table already answers.
     */
    private const LIMIT = 2;

    /**
     * Shortest word worth comparing by edit distance.
     *
     * Below four characters a single edit is most of the word, so "las" would
     * resemble half a portfolio. Short brand names still match exactly; they
     * just do not get typo tolerance, which is the safe direction to fail.
     */
    private const MIN_FUZZY_LENGTH = 4;

    /**
     * Brands this question names outright.
     *
     * @return array<int, Client> Longest name first, so the most specific match
     *                            survives when one brand's name contains another's.
     */
    public function in(string $question, User $user): array
    {
        $haystack = $this->normalise($question);

        if ($haystack === '') {
            return [];
        }

        $found = [];

        foreach ($this->candidates($user) as $client) {
            foreach ([$client->name, $client->slug] as $needle) {
                if ($this->mentions($haystack, $this->normalise((string) $needle))) {
                    $found[$client->id] = $client;

                    break;
                }
            }

            if (count($found) === self::LIMIT) {
                break;
            }
        }

        return array_values($found);
    }

    /**
     * Brands the question ALMOST names — one or two typos away.
     *
     * ⚠️ A SUGGESTION, NOT A MATCH, and that difference is the whole point. The
     * beta review found that typing "akea" got "reconozco Alea, pero no hay
     * datos": the exact pass found nothing, so no ficha was attached, so the
     * only thing left to answer from was the summary table — which carries
     * counts, not content.
     *
     * The obvious fix was to attach Alea's ficha anyway. Breakfast asked for the
     * better one: ask first. So these brands are handed to the model as a
     * question to put to the person, and their fichas are deliberately NOT sent.
     * That is what makes the confirmation real rather than ceremony — with no
     * ficha in the prompt the model cannot answer about the brand even if it
     * ignores the instruction. When the person confirms, the next turn resolves
     * through resolvedInReply() and the ficha arrives then.
     *
     * Only consulted when the exact pass came back empty, so a question that
     * spells a brand correctly behaves exactly as it did before.
     *
     * @return array<int, Client>
     */
    public function nearMisses(string $question, User $user): array
    {
        $words = array_values(array_filter(
            explode(' ', $this->normalise($question)),
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_FUZZY_LENGTH,
        ));

        if ($words === []) {
            return [];
        }

        $found = [];

        foreach ($this->candidates($user) as $client) {
            foreach ([$client->name, $client->slug] as $needle) {
                if ($this->resembles($words, $this->normalise((string) $needle))) {
                    $found[$client->id] = $client;

                    break;
                }
            }

            if (count($found) === self::LIMIT) {
                break;
            }
        }

        return array_values($found);
    }

    /**
     * The brand the assistant itself named in its previous turn.
     *
     * THIS IS WHAT MAKES "SÍ" WORK. nearMisses() has the model ask "¿te refieres
     * a Alea?", and the answer to that is one word naming no brand at all — so
     * without this the confirmation would land in exactly the same empty context
     * the typo did, and the person would be asked the same question twice.
     *
     * Deliberately narrow: the caller only reaches for it when the current
     * question names no brand of its own, and it only accepts a previous turn
     * that named exactly ONE brand. Two candidates in her last answer means
     * "which of them?" is still open, and picking one would be guessing again.
     *
     * @param  string  $reply  The assistant's previous turn, or '' if there is none.
     */
    public function resolvedInReply(string $reply, User $user): ?Client
    {
        $named = $this->in($reply, $user);

        return count($named) === 1 ? $named[0] : null;
    }

    /**
     * Every brand this user may see, longest name first.
     *
     * ⚠️ Client::visibleTo() IS THE SCOPE, and every pass shares it. A typo can
     * therefore never reach a brand the correct spelling could not: naming a
     * brand you were never put on finds nothing, misspelt or not.
     *
     * @return Collection<int, Client>
     */
    private function candidates(User $user): Collection
    {
        return Client::query()
            ->visibleTo($user)
            ->orderByRaw('LENGTH(name) DESC')
            ->get();
    }

    /**
     * Is any of these words a typo of this brand's name?
     *
     * The tolerance scales with length because one fixed threshold is wrong at
     * both ends: a single edit in a four-letter name is a quarter of it, while
     * two edits across "the coffee club" is a plausible pair of slips. Multi-word
     * names are compared word by word, so "coffe club" still finds it.
     *
     * Distance zero is refused: an exact word means the exact pass already had
     * its chance and declined, which happens when the word matched a fragment
     * of the name rather than the whole of it.
     *
     * @param  array<int, string>  $words
     */
    private function resembles(array $words, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        foreach (explode(' ', $needle) as $part) {
            if (mb_strlen($part) < self::MIN_FUZZY_LENGTH) {
                continue;
            }

            $tolerance = mb_strlen($part) < 7 ? 1 : 2;

            foreach ($words as $word) {
                $distance = levenshtein($word, $part);

                if ($distance > 0 && $distance <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whole words only.
     *
     * A plain str_contains would match "alea" inside "aleatorio" and staple the
     * wrong brand's 48 entregables to the prompt. The boundaries are checked
     * against the normalised string, where every separator is already a space.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?:^| )'.preg_quote($needle, '/').'(?:$| )/u', $haystack) === 1;
    }

    /**
     * Lowercased, unaccented, punctuation flattened to spaces.
     *
     * So "¿Cómo va ALEA?" finds "Alea", and "the-coffee-club" finds "The Coffee
     * Club" — a slug is a name with its spaces turned into hyphens, and both
     * end up as the same string here.
     */
    private function normalise(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        $plain = strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $plain));
    }
}
