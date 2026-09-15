<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\Message;

/**
 * Assembles the message array for an admin question.
 *
 * ┌─ block 1: admin system prompt ─────────┐  stable forever
 * ├─ block 2: portfolio snapshot ──────────┤  stable until the data changes
 * ├─ block 2b: one brand's ficha ──────────┤  only when the question names a brand
 * └─ block 3: today + spend + the question ┘  volatile — changes every request
 *
 * ⚠️ SPEND RIDES IN BLOCK 3, with the clock, and for the same reason. Answering
 * a question writes a row to ai_usage_logs, so any total is already stale by
 * the next turn; in the cacheable prefix it would change the prefix every time
 * and silently take the hit rate to zero. See SpendDigest.
 *
 * A SEPARATE PATH FROM BrandContextBuilder, deliberately, not an `if` inside
 * it. That builder is per-client by construction and never sees a second
 * brand; this one crosses every brand the user may see, which is exactly what
 * the client's assistant is forbidden to do. Keeping them as two classes means
 * a mistake in one cannot become a leak in the other.
 *
 * The caching rules are the same as the client side and for the same reason:
 * blocks 1 and 2 must serialise identically between requests, so nothing
 * volatile may sit above block 3. Today's date is stapled to the question.
 */
final class AdminContextBuilder
{
    public function __construct(
        private readonly PortfolioSnapshot $snapshot,
        private readonly MentionedBrands $mentions,
        private readonly SpendDigest $spend,
    ) {}

    /**
     * One turn's brand resolution, memoised.
     *
     * resolve() runs up to three queries and both cacheablePrefix() and
     * suggestion() need its answer. Recomputing would double the cost and, more
     * to the point, risk the two disagreeing.
     *
     * @var array<string, array{fichas: array<int, Client>, suggest: array<int, Client>}>
     */
    private array $resolved = [];

    /**
     * @param  array<int, Message>  $history  This person's earlier turns, oldest
     *                                        first, and nobody else's.
     * @param  array<int, Attachment>  $attachments  A pasted
     *                                               screenshot or a recorded voice note, sent with this question.
     * @return array<int, Message>
     */
    public function forQuestion(
        User $user,
        string $question,
        ?Client $client = null,
        array $history = [],
        array $attachments = [],
    ): array {
        return [
            // Between the cached prefix and the question, deliberately. Blocks
            // 1 and 2 keep their exact bytes, so the cache still hits; the
            // thread then grows by APPENDING, which means each turn extends the
            // matched prefix rather than rewriting anything above it.
            ...$this->cacheablePrefix($user, $client, $question, $history),
            ...$history,
            // Attachments ride on the question, never above it: an image in
            // the cacheable prefix is a new prefix on every turn, and a photo
            // is the most expensive thing that could possibly go there.
            Message::userWithAttachments(
                $this->today()
                    .$this->speaker($user)
                    .$this->continuation($history)
                    .$this->suggestion($user, $client, $question, $history)
                    .$this->spend->forUser($user)
                    ."\n\n".trim($question),
                $attachments,
            ),
        ];
    }

    /**
     * Blocks 1, 2 and 2b.
     *
     * The ficha is appended rather than replacing the snapshot: "¿cómo va The
     * Coffee Club comparado con las demás?" names one brand and still needs the
     * others. Narrowing the dropdown is a hint about focus, not a filter.
     *
     * TWO WAYS TO NAME A BRAND. The dropdown is one; typing the name in the
     * question is the other, and it is the one people actually use — see
     * MentionedBrands. Before it existed, a question about one brand asked in
     * "todas las marcas" mode got only the summary row, so anything about the
     * brand's actual content was unanswerable.
     *
     * @param  string  $question  Read for brand names only. It is NOT put in
     *                            this block — the question itself is volatile
     *                            and belongs on the user turn.
     * @return array<int, Message>
     */
    public function cacheablePrefix(
        User $user,
        ?Client $client = null,
        string $question = '',
        array $history = [],
    ): array {
        $messages = [
            Message::system((string) config('ai.admin_prompt')),
        ];

        $context = "# Estado del portafolio\n\n".$this->snapshot->forUser($user);

        foreach ($this->resolve($user, $client, $question, $history)['fichas'] as $subject) {
            $context .= "\n\n# Ficha ampliada — la pregunta es sobre esta marca\n\n"
                .$this->snapshot->ficha($subject);
        }

        // The last stable block carries the breakpoint: on Gemini it is a
        // boundary, so marking this one caches the prompt above it too. See
        // BrandContextBuilder for the same reasoning on the client side.
        $messages[] = Message::cacheableSystem($context);

        return $messages;
    }

    /**
     * Who this turn is about: which brands get a ficha, and which are only
     * worth asking about.
     *
     * FOUR WAYS TO NAME A BRAND, in order of confidence:
     *
     *   1. the dropdown             — explicit, always attached
     *   2. the name, spelled right  — MentionedBrands::in()
     *   3. the name, misspelled     — a SUGGESTION, never a ficha
     *   4. nothing, right after she asked "¿te refieres a X?" — the confirmation
     *
     * The third is the one Breakfast asked for and it is the reason this method
     * returns two lists instead of one. A near miss deliberately attaches NO
     * ficha: the model is told to ask, and with no ficha in the prompt it cannot
     * do anything else. See MentionedBrands::nearMisses().
     *
     * The fourth closes the loop. "Sí" names no brand, so without it the
     * confirmation would land in the same empty context as the typo and the
     * person would be asked twice. It only runs when the question names nothing
     * of its own, so a normal question behaves exactly as it did before.
     *
     * Memoised because cacheablePrefix() and suggestion() both need the answer
     * and each pass costs a query — one turn, one resolution.
     *
     * @param  array<int, Message>  $history
     * @return array{fichas: array<int, Client>, suggest: array<int, Client>}
     */
    private function resolve(User $user, ?Client $client, string $question, array $history): array
    {
        $key = $user->id.'|'.($client?->id ?? '-').'|'.md5($question).'|'.count($history);

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $fichas = $client !== null ? [$client->id => $client] : [];

        foreach ($this->mentions->in($question, $user) as $mentioned) {
            $fichas[$mentioned->id] ??= $mentioned;
        }

        $suggest = [];

        if ($fichas === []) {
            $near = $this->mentions->nearMisses($question, $user);
            $offered = $this->mentions->resolvedInReply($this->lastReply($history), $user);

            if ($near === []) {
                // Nothing resembling a brand either — so if she asked about one
                // last turn, this is the answer to that question.
                if ($offered !== null) {
                    $fichas[$offered->id] = $offered;
                }
            } elseif ($offered !== null && $this->isAmong($offered, $near)) {
                // She already asked about this brand and the person typed the
                // same misspelling again. Asking a second time would be a loop,
                // and the repetition is confirmation enough.
                $fichas[$offered->id] = $offered;
            } else {
                $suggest = $near;
            }
        }

        return $this->resolved[$key] = [
            'fichas' => array_values($fichas),
            'suggest' => $suggest,
        ];
    }

    /** @param array<int, Client> $brands */
    private function isAmong(Client $needle, array $brands): bool
    {
        foreach ($brands as $brand) {
            if ($brand->id === $needle->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The assistant's most recent turn, or '' when the thread is empty.
     *
     * @param  array<int, Message>  $history
     */
    private function lastReply(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role === 'assistant') {
                return $history[$i]->text();
            }
        }

        return '';
    }

    /**
     * "That name does not exist, but this one is close — ask."
     *
     * BLOCK 3, like everything else volatile. It names the brand but carries
     * none of its content: the ficha is withheld on purpose, so the model has
     * nothing to answer from until somebody confirms. See
     * MentionedBrands::nearMisses().
     *
     * ⚠️ TAKES THE SAME $history cacheablePrefix() was given. With a different
     * one it would resolve differently — and the failure would be silent and
     * absurd: the ficha attached above while the line below still asked whether
     * that was the brand somebody meant.
     *
     * @param  array<int, Message>  $history
     */
    private function suggestion(User $user, ?Client $client, string $question, array $history): string
    {
        $suggest = $this->resolve($user, $client, $question, $history)['suggest'];

        if ($suggest === []) {
            return '';
        }

        $names = array_map(static fn (Client $brand): string => $brand->name, $suggest);

        return count($names) === 1
            ? 'Ese nombre de marca no existe tal cual, pero se parece a: '.$names[0]
                .'. Pregúntale a quien escribe si se refiere a esa marca y no respondas '
                ."la consulta todavía.\n\n"
            : 'Ese nombre de marca no existe tal cual, pero se parece a: '.implode(' y ', $names)
                .'. Pregúntale a quien escribe a cuál de las dos se refiere y no respondas '
                ."la consulta todavía.\n\n";
    }

    /**
     * "You are mid-conversation" — the fix for Brandy greeting twice.
     *
     * Stated rather than inferred from the turns below it: a model does not
     * reliably notice that a thread already exists, but it does follow an
     * instruction. Block 3, so it costs no cache. The client side carries the
     * same line — see BrandContextBuilder::preamble().
     *
     * @param  array<int, Message>  $history
     */
    private function continuation(array $history): string
    {
        return $history === []
            ? ''
            : "Esta conversación ya está empezada: no saludes ni te presentes de nuevo.\n\n";
    }

    /**
     * Today's date, on the user turn.
     *
     * THE ONLY CLOCK IN THE REQUEST. "¿Qué marcas llevan más de un mes sin
     * actividad?" is arithmetic against today, and every date it is compared
     * with is an absolute one in block 2. Putting this above the prefix would
     * expire the cache every midnight, silently.
     */
    private function today(): string
    {
        return 'Hoy es '.now()->translatedFormat('l j \d\e F \d\e Y').".\n\n";
    }

    /**
     * Who is asking, for the greeting.
     *
     * ON THE USER TURN, beside the date and for the same reason. This block is
     * the only volatile one: block 1 is the house prompt and block 2 is the
     * portfolio, and both are shared by every admin on the account. A name in
     * either would give each person their own prefix and turn one cached
     * portfolio into one per member of staff — see BrandContextBuilder, which
     * carries the long version of this argument.
     *
     * The instruction to USE it is in ai.admin_prompt, where it is stable.
     */
    private function speaker(User $user): string
    {
        $name = $user->firstName();

        return $name === null ? '' : 'Te escribe '.$name.".\n\n";
    }
}
