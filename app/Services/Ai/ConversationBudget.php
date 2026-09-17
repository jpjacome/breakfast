<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * How full a conversation is, and whether it is time to fold the old part up.
 *
 * THE ONE CLASS THAT DECIDES IT. The meter under the composer, the summariser
 * and any test all read it from here — three places counting for themselves is
 * three chances for a meter to say 40% while the summariser fires.
 *
 * ⚠️ IT COUNTS CHARACTERS, NOT TOKENS, AND THAT IS THE RIGHT UNIT HERE.
 * Tokens are what the provider bills, but they arrive in `ai_usage_logs` AFTER
 * a request — so a token meter cannot show anything until the first answer has
 * landed, is always one turn stale, and cannot move while somebody is typing.
 * Characters are countable instantly, on the server or in the browser, which is
 * what makes this a warning rather than a receipt.
 *
 * ⚠️ AND THE THREAD REALLY IS TEXT, which is what makes the proxy honest. An
 * earlier turn replays its files BY NAME — re-inlining a screenshot on every
 * later question would multiply a conversation's cost by the size of its first
 * image (CLAUDE.md §7). So the bytes never accumulate; only words do.
 *
 * Roughly 3.5–4 characters per token in Spanish prose, denser for tables and
 * code. The ledger stays the reality check: `ai_usage_logs.prompt_tokens` is
 * measured, and if it ever diverges from what this predicts, this is wrong.
 */
final class ConversationBudget
{
    /**
     * Exchanges kept verbatim, never folded into a summary.
     *
     * ⚠️ NOT A ROUND NUMBER PULLED OUT OF THE AIR. §3 of the brief asks Brandy
     * to hold references like *"une la 1 y la 3"*, *"hazla más corta"*,
     * *"convierte esa idea en un reel"*. Every one of those points at the turns
     * immediately before it, so a summary that swallowed them would break the
     * exact behaviour item 5 exists to deliver. Four exchanges is eight rows.
     */
    public const KEEP_EXCHANGES = 4;

    /** Where the meter starts warning, as a fraction of the budget. */
    public const WARN_AT = 0.8;

    /**
     * The characters of a conversation as the model will read it.
     *
     * Summary included: once the old part is folded up, the summary IS part of
     * what gets sent, so a meter that ignored it would reset to zero and then
     * climb from a floor it refuses to admit to.
     */
    public function used(Conversation $conversation): int
    {
        $body = $conversation->replayable()->sum(
            static fn (AssistantMessage $m): int => mb_strlen((string) $m->body),
        );

        return $body + mb_strlen((string) $conversation->summary);
    }

    public function limit(): int
    {
        return (int) config('ai.conversation.budget_chars');
    }

    /** 0–100, capped, for the meter. */
    public function percent(Conversation $conversation): int
    {
        $limit = $this->limit();

        if ($limit <= 0) {
            return 0;
        }

        return (int) min(100, round($this->used($conversation) / $limit * 100));
    }

    public function shouldWarn(Conversation $conversation): bool
    {
        return $this->percent($conversation) >= self::WARN_AT * 100;
    }

    /**
     * Time to fold the old part up.
     *
     * ⚠️ IT ALSO REQUIRES ENOUGH TURNS TO BE WORTH IT. A conversation of three
     * enormous messages can cross the character budget while having nothing
     * old to summarise — folding it would replace the only three things said
     * with a paraphrase of them, which is strictly worse than leaving it alone.
     */
    public function shouldSummarise(Conversation $conversation): bool
    {
        if ($this->used($conversation) < $this->limit()) {
            return false;
        }

        return $conversation->replayable()->count() > self::KEEP_EXCHANGES * 2;
    }

    /**
     * What a summary would cover: everything but the last few exchanges.
     *
     * Returns the id to record as `summarised_through_id`, or null when there
     * is nothing old enough to fold.
     *
     * @return array{through: ?int, messages: Collection<int, AssistantMessage>}
     */
    public function foldable(Conversation $conversation): array
    {
        $replayable = $conversation->replayable();
        $keep = self::KEEP_EXCHANGES * 2;

        if ($replayable->count() <= $keep) {
            return ['through' => null, 'messages' => collect()];
        }

        $old = $replayable->slice(0, $replayable->count() - $keep)->values();

        return [
            'through' => $old->last()?->id,
            'messages' => $old,
        ];
    }
}
