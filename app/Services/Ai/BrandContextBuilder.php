<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\BrandContext;
use App\Services\Ai\Data\Message;

/**
 * Assembles the message array for a brand question.
 *
 * ┌─ block 1: house system prompt ──┐  stable across ALL clients
 * ├─ block 2: brand context ────────┤  stable per client
 * └─ block 3: the question ─────────┘  volatile — changes every request
 *
 * The ordering is the entire caching mechanism ON DEEPSEEK, which caches on an
 * exact prefix match with no markers, so blocks 1 and 2 must serialise
 * identically between requests for the same client.
 *
 * GEMINI DOES NOT WORK THIS WAY. It wants an explicit breakpoint, which is
 * what the last block of the prefix carries — see cacheablePrefix(). The
 * breakpoint marks a boundary, so marking the LAST stable block caches
 * everything above it too; that is why block 1 is only marked when there is no
 * block 2 to mark instead.
 *
 * Both mechanisms depend on the same thing: nothing volatile above block 3.
 * A timestamp, a user name, a request id, a "responde en {idioma}" hint — any
 * of them drops the hit rate to zero, silently and with no error. The only
 * signal is usage.prompt_cache_hit_tokens sitting at 0 and the bill going up
 * about 150x.
 *
 * There is a test asserting prefix stability. If you change this class and it
 * fails, the test is right.
 */
final class BrandContextBuilder
{
    /**
     * Messages for a single brand question.
     *
     * @return array<int, Message>
     */
    public function forQuestion(
        BrandContext $context,
        string $question,
        ?string $speaker = null,
    ): array {
        return [
            ...$this->cacheablePrefix($context),
            Message::user($this->preamble($speaker).trim($question)),
        ];
    }

    /**
     * Messages for a multi-turn conversation.
     *
     * History is appended after the cacheable prefix, so growing a thread
     * extends the cached prefix rather than invalidating it.
     *
     * @param  array<int, Message>  $history
     * @param  array<int, Attachment>  $attachments  A pasted
     *                                               screenshot, a recorded voice note. They ride on the QUESTION,
     *                                               never above it: an image in the cacheable prefix would be a new
     *                                               prefix on every turn, and the bytes of a photo are the most
     *                                               expensive thing that could possibly go there.
     * @return array<int, Message>
     */
    public function forConversation(
        BrandContext $context,
        array $history,
        string $question,
        array $attachments = [],
        ?string $speaker = null,
    ): array {
        return [
            ...$this->cacheablePrefix($context),
            ...$history,
            Message::userWithAttachments(
                // continuing: the thread already has turns, so this is not the
                // opening of a conversation and must not be greeted as one.
                $this->preamble($speaker, continuing: $history !== []).trim($question),
                $attachments,
            ),
        ];
    }

    /**
     * What the model is told before the question itself: the date, and who is
     * asking.
     *
     * BLOCK 3, BOTH OF THEM, AND THAT IS THE WHOLE POINT.
     *
     * The date is the only clock allowed in a request. A client asking
     * "¿cuándo terminamos?" or "¿cuándo es la próxima reunión?" needs the model
     * to do arithmetic against today, and everything it computes against —
     * when a step started, when a meeting is — is an absolute date in block 2.
     *
     * The NAME is here for the same reason and a sharper one. Block 2 is stable
     * PER CLIENT and a brand has several people, so interpolating a name into
     * it would split one cached brand context into one per person. Block 1
     * would be worse still: a different prefix for every user in the system,
     * which is the ~150x case this class exists to prevent. The class docblock
     * lists "a user name" as an example precisely because it is the tempting
     * one — it reads so much more naturally up there.
     *
     * The INSTRUCTION to greet by name lives in block 1, where it is stable
     * and cached. Only the name itself is volatile, so only the name is here.
     *
     * A null speaker prints no line at all rather than an empty greeting: an
     * unnamed account should read as nothing, not as "Te escribe .".
     */
    private function preamble(?string $speaker = null, bool $continuing = false): string
    {
        $lines = ['Hoy es '.now()->translatedFormat('l j \d\e F \d\e Y').'.'];

        if ($speaker !== null && trim($speaker) !== '') {
            $lines[] = 'Te escribe '.trim($speaker).'.';
        }

        // ⚠️ THE FIX FOR "BRANDY VUELVE A SALUDAR", from the beta review. She
        // had no way to tell a fresh conversation from one resumed after lunch:
        // every turn arrived looking identical, so the greeting rule in block 1
        // fired whenever a question read like an opener and she introduced
        // herself to somebody she had been talking to all morning.
        //
        // Stated rather than inferred from the history array, because a model
        // does not reliably notice that earlier turns exist — it notices an
        // instruction. Block 3, so it costs no cache.
        if ($continuing) {
            $lines[] = 'Esta conversación ya está empezada: no saludes ni te presentes de nuevo.';
        }

        return implode("\n", $lines)."\n\n";
    }

    /**
     * Blocks 1 and 2. Everything here must be byte-stable per client.
     *
     * The LAST block carries the breakpoint, and only the last one. On Gemini
     * a breakpoint is a boundary rather than a label: marking block 2 caches
     * block 1 along with it, so a second marker on block 1 would buy nothing
     * and spend one of the four OpenRouter allows.
     *
     * On DeepSeek this changes nothing at all — cacheableSystem() returns the
     * same plain string when the provider does not use breakpoints, so the
     * prefix bytes are identical and the exact-match cache still hits. There
     * is a test for that.
     *
     * @return array<int, Message>
     */
    public function cacheablePrefix(BrandContext $context): array
    {
        $house = (string) config('ai.system_prompt');

        if ($context->isEmpty()) {
            return [Message::cacheableSystem($house)];
        }

        return [
            Message::system($house),
            Message::cacheableSystem($context->toPrompt()),
        ];
    }

    /**
     * Debug helper: the exact bytes whose stability determines cache hits.
     * Diff this across two requests when the hit rate is unexpectedly zero.
     */
    public function prefixFingerprint(BrandContext $context): string
    {
        $rendered = implode("\n", array_map(
            static fn (Message $m): string => $m->role.':'.$m->text(),
            $this->cacheablePrefix($context),
        ));

        return hash('xxh128', $rendered);
    }
}
