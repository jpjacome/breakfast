<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Conversation;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use Illuminate\Support\Collection;

/**
 * Fold the old part of a conversation into a summary — item 5.
 *
 * ⚠️ THIS IS THE ONE PLACE IN THE APP WHERE MODEL OUTPUT BECOMES FACT ON A
 * LATER TURN. Everywhere else — proposals, Egg layers, entregables — a person
 * accepts every word before it counts, and there is no state where the model
 * authored a value alone (CLAUDE.md §8 rule 4). A summary skips that: it just
 * starts being her memory of what was said.
 *
 * Two things make that acceptable rather than a hole in the model:
 *
 *   1. It summarises HER OWN CONVERSATION, not the brand. Nothing here can
 *      reach `brand_deliverables` or `brand_eggs`; the tiers above it are
 *      untouched, and a wrong summary makes her forgetful, not wrong about the
 *      brand.
 *   2. It is VISIBLE AND CORRECTABLE. The meter under the composer opens what
 *      was folded up, and it is editable there — which is the same
 *      accept-or-edit shape as every other thing she writes, arriving after
 *      the fact instead of before it.
 *
 * ⚠️ IT NEVER THROWS. It runs after an answer has already landed and been paid
 * for; a provider hiccup must not turn a good turn into a 500. A conversation
 * that fails to fold simply stays long and tries again next turn.
 *
 * ⚠️ IT SPENDS THE PROMPT CACHE, ONCE. The thread extends the cached prefix, so
 * replacing twenty turns with a summary changes those bytes and the next
 * request pays full price for the prefix a single time. That is why this fires
 * on a threshold and never continuously.
 */
final class SummarisesConversations
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly ConversationBudget $budget,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Fold if it is time to. Returns whether anything changed.
     *
     * `$force` is the meter's "resumir ahora": somebody who knows a topic is
     * finished should not have to wait for a threshold.
     */
    public function handle(Conversation $conversation, bool $force = false): bool
    {
        if (! $force && ! $this->budget->shouldSummarise($conversation)) {
            return false;
        }

        ['through' => $through, 'messages' => $old] = $this->budget->foldable($conversation);

        if ($through === null || $old->isEmpty()) {
            return false;
        }

        $summary = $this->write($conversation, $old);

        if ($summary === null) {
            return false;
        }

        $conversation->forceFill([
            'summary' => $summary,
            'summarised_through_id' => $through,
        ])->save();

        return true;
    }

    /**
     * @param  Collection<int, AssistantMessage>  $old
     */
    private function write(Conversation $conversation, $old): ?string
    {
        $messages = [
            // Byte-identical for every conversation on every surface, forever,
            // so this block is cacheable. Nothing about WHICH conversation is
            // being folded may appear above the user turn (CLAUDE.md §7).
            Message::cacheableSystem($this->instructions()),
            Message::user($this->turn($conversation, $old)),
        ];

        try {
            $response = $this->client->complete($messages, role: 'utility', options: [
                // The floor. A summary is a record of what was said, and the
                // default temperature is set for brand copy — here it would
                // paraphrase a decision into something nobody made.
                'temperature' => 0.1,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $conversation->client_id,
                userId: $conversation->user_id,
                operation: 'conversation-summary',
                model: $this->client->modelFor('utility'),
            );

            // Never throws: the answer this followed is already on screen.
            return null;
        }

        $this->usage->record(
            $response,
            clientId: $conversation->client_id,
            userId: $conversation->user_id,
            operation: 'conversation-summary',
        );

        $summary = trim((string) $response->content);

        return $summary === '' ? null : $summary;
    }

    /**
     * What the summariser is for, and what it may not lose.
     *
     * ⚠️ THE LIST OF WHAT TO KEEP IS THE WHOLE PROMPT. Whatever this drops is
     * gone for good — there is no second pass over the original turns once
     * `summarised_through_id` moves — so "summarise the conversation" on its
     * own would quietly discard the half that matters.
     *
     * ⚠️ REJECTED IDEAS ARE ON THAT LIST, and they are the one people forget.
     * Without them she re-proposes what was already turned down, which is the
     * single most irritating way an assistant can fail and reads as not having
     * listened.
     */
    private function instructions(): string
    {
        return <<<'PROMPT'
        Resumes una conversación entre una persona y una asistente de marca,
        para que la asistente pueda seguir la conversación sin releerla entera.

        No es un resumen literario. Es una memoria de trabajo.

        CONSERVA SIEMPRE:
        - Las decisiones que se tomaron, con las palabras exactas cuando eran
          exactas (un claim, un nombre, una frase aprobada).
        - Nombres propios, cifras, fechas y enlaces.
        - Lo que la persona PIDIÓ que se hiciera y todavía está pendiente.
        - Lo que la persona RECHAZÓ o descartó, y por qué si lo dijo. Esto es
          tan importante como lo aceptado: sin ello volverías a proponer algo
          que ya se descartó.
        - El tema en el que quedaron, para que lo siguiente que digas tenga
          continuidad.

        PUEDES DESCARTAR:
        - Saludos, cortesías y confirmaciones ("perfecto", "dale", "gracias").
        - Explicaciones tuyas que ya no hacen falta porque la decisión se tomó.
        - Reformulaciones de lo mismo dicho tres veces.

        REGLAS:
        - Escribe en español, en tercera persona, en pasado.
        - No inventes nada. Si algo quedó ambiguo, dilo como quedó: ambiguo.
        - No opines ni recomiendes. Aquí sólo registras.
        - Máximo 20 líneas. Si no cabe, prioriza decisiones y pendientes.
        PROMPT;
    }

    /**
     * @param  Collection<int, AssistantMessage>  $old
     */
    private function turn(Conversation $conversation, $old): string
    {
        $transcript = $old
            ->map(static fn (AssistantMessage $m): string => ($m->isFromAssistant() ? 'ASISTENTE' : 'PERSONA')
                .': '.trim((string) $m->body))
            ->implode("\n\n");

        $previous = trim((string) $conversation->summary);

        // ⚠️ THE EXISTING SUMMARY GOES IN AS MATERIAL, NOT AS SOMETHING TO
        // APPEND TO. A second fold that ignored the first would discard
        // everything from the beginning of the conversation — the summary IS
        // the early part by then, and it is the only copy the model has.
        $head = $previous === ''
            ? 'Resume esta conversación:'
            : 'Ya existe este resumen de lo anterior. Incorpóralo y amplíalo con lo nuevo, '
                ."sin perder nada de lo que ya decía:\n\n<<<\n{$previous}\n>>>\n\nLo nuevo:";

        return $head."\n\n".$transcript;
    }
}
