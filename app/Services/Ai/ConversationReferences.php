<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Conversation;
use App\Models\User;

/**
 * Pointing at another conversation from inside this one — item 5.
 *
 * Somebody copies a reference off a row in the history panel, pastes it into a
 * question — *"retoma lo que quedó en #conv-12"* — and the turn arrives with
 * that conversation's own memory attached.
 *
 * ⚠️ IT RESOLVES EVERY REFERENCE THROUGH isReachableBy(), WHICH IS THE WHOLE
 * SAFETY OF THE FEATURE. A reference is a NUMBER a person types, so without
 * that check pasting `#conv-7` would be a way to read somebody else's thread —
 * an admin reading a client's, or one brand's turns arriving inside another's
 * prompt. Same three questions as everywhere else: whose, which side, which
 * brand. An id that fails any of them is SILENTLY DROPPED rather than refused,
 * because a refusal would answer the question the check exists to hide: whether
 * that conversation exists at all.
 *
 * ⚠️ WHAT IT GATHERS IS THE SUMMARY **AND** THE LAST FEW TURNS, not one or the
 * other. The summary alone was the obvious design and is wrong most of the
 * time: SummarisesConversations only fires once a conversation crosses the
 * character budget, so the great majority of conversations have no summary at
 * all and a reference to one would carry nothing. The tail alone loses exactly
 * what somebody referencing an old conversation is usually after — what was
 * decided at the start. Both, capped, degrades gracefully in every case.
 *
 * ⚠️ AND IT ALL GOES IN BLOCK 3, on the user turn, never above it. A referenced
 * conversation changes from question to question, so a block of it anywhere in
 * the cacheable prefix is a new prefix every request (CLAUDE.md §7).
 *
 * @see docs/implementaciones.md — item 5
 */
final class ConversationReferences
{
    /**
     * What a pasted reference looks like.
     *
     * ⚠️ DISTINCTIVE ON PURPOSE. A bare "#12" is something people write for
     * other reasons — a ticket, a version, a street number — and a turn that
     * silently grew four thousand characters because somebody mentioned an
     * invoice number would be baffling and expensive. Nothing types `#conv-12`
     * by accident, and nothing produces it except the panel's own menu.
     */
    private const PATTERN = '/#conv-(\d+)/';

    /**
     * How many may be pulled into one turn.
     *
     * ⚠️ THREE, BECAUSE EACH ONE IS PAID FOR ON EVERY TURN IT APPEARS IN. A
     * question naming ten conversations is a question that quietly costs ten
     * times what it looks like, and nobody holding ten threads in their head
     * needed the model to read all of them.
     */
    private const MAX_REFERENCES = 3;

    /**
     * Characters allowed per referenced conversation.
     *
     * Characters and not tokens for the reason ConversationBudget gives: they
     * are countable here and now, before any request has been made.
     */
    private const CHARS_EACH = 4000;

    /**
     * The block to append to the user turn, or null if nothing was referenced.
     *
     * `$clientId` is null on the dashboard and the active brand on the portal —
     * the same scoping the panel itself uses, passed in rather than worked out
     * here, because which brand a request is about is ActiveBrand's question.
     */
    public function digest(
        User $user,
        string $surface,
        ?int $clientId,
        string $question,
        ?Conversation $current = null,
    ): ?string {
        if (! preg_match_all(self::PATTERN, $question, $found)) {
            return null;
        }

        $ids = array_slice(array_unique(array_map('intval', $found[1])), 0, self::MAX_REFERENCES);

        $blocks = [];

        foreach ($ids as $id) {
            // Referencing the conversation you are already in is a no-op, not
            // an error: everything in it is already being replayed, and
            // including it again would say the same things twice in one prompt.
            if ($current !== null && $id === $current->id) {
                continue;
            }

            $conversation = Conversation::query()->find($id);

            // Dropped in silence — see the class note. Not "no encontré esa
            // conversación", which is the answer the check exists to withhold.
            if ($conversation === null
                || ! $conversation->isReachableBy($user, $surface, $clientId)) {
                continue;
            }

            $blocks[] = $this->one($conversation);
        }

        if ($blocks === []) {
            return null;
        }

        /*
         * ⚠️ THE HEADING IS LOAD-BEARING. Without it this reads as more of the
         * present conversation, and the model answers as though those decisions
         * were just made. It is somewhere else's record, and it is old.
         *
         * ⚠️ AND IT IS NOT BRAND DATA. What a brand IS comes from the
         * entregables and the Egg, only (CLAUDE.md §8). A past conversation is
         * what somebody SAID, which is a different kind of thing, and saying so
         * here is cheaper than discovering later that a half-finished idea from
         * March got quoted back as the brand's promise.
         */
        return "\n\n".implode("\n\n", [
            'OTRAS CONVERSACIONES QUE TE ESTÁN CITANDO',
            'Esto es lo que se habló en otro momento, no lo que la marca ES. Úsalo para '
                .'retomar el hilo, nunca como definición de nada. Si algo de aquí contradice '
                .'los entregables o el Brand Egg, manda el entregable y lo dices.',
            ...$blocks,
        ]);
    }

    /** One referenced conversation: what it was called, plus its memory. */
    private function one(Conversation $conversation): string
    {
        $lines = ['--- #conv-'.$conversation->id.' · «'.($conversation->title ?? 'Sin título').'»'];

        $summary = trim((string) $conversation->summary);

        if ($summary !== '') {
            $lines[] = 'Resumen de lo anterior: '.$summary;
        }

        $tail = $this->tail($conversation, self::CHARS_EACH - mb_strlen($summary));

        if ($tail !== '') {
            $lines[] = $summary === '' ? $tail : 'Y lo último que se dijo ahí: '.$tail;
        }

        return implode("\n", $lines);
    }

    /**
     * The end of the conversation, newest-last, within what is left of the cap.
     *
     * ⚠️ TAKEN FROM THE END, NOT THE START. What somebody wants when they point
     * at an old conversation is where it got to — and where it started is
     * already in the summary when there is one.
     */
    private function tail(Conversation $conversation, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        $turns = $conversation->messages()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        $kept = [];
        $used = 0;

        // Walked backwards so the cap drops the OLDEST of the tail, then put
        // back in order — a transcript read newest-first is a different
        // conversation.
        foreach ($turns->reverse() as $turn) {
            $line = ($turn->isFromAssistant() ? 'ASISTENTE' : 'PERSONA').': '.trim((string) $turn->body);

            if ($used + mb_strlen($line) > $budget) {
                break;
            }

            $kept[] = $line;
            $used += mb_strlen($line);
        }

        return implode("\n", array_reverse($kept));
    }

    /** Whether this surface keys its conversations to a brand. */
    public static function scopesToBrand(string $surface): bool
    {
        return $surface === AssistantMessage::SURFACE_PORTAL;
    }
}
