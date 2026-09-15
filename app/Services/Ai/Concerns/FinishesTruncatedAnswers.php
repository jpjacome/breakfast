<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;

/**
 * An answer that hit the token ceiling gets one more call to finish itself.
 *
 * WHY THIS EXISTS. The beta review asked Brandy to compare two brands "por
 * etapa, último avance, próximo pendiente, responsable y fecha". She said she
 * would, and the message ended there — the comparison never arrived. A reply
 * that promises and stops is worse than a short one, because the person waits
 * for the rest.
 *
 * There are two ways that happens and this fixes the mechanical one. The model
 * choosing to stop after its own preamble is a prompt problem, and both prompts
 * in config/ai.php now forbid announcing something without delivering it. But a
 * genuinely long answer — five fields across two brands — can also run into
 * max_tokens, and no instruction can help there: the sentence is cut mid-word
 * because the budget ran out. finish_reason tells us which happened, and this
 * is the answer to the second.
 *
 * ONE CONTINUATION, NEVER A LOOP. If the second call also runs out, the answer
 * is returned as it stands rather than billing a third. A question whose honest
 * answer is longer than two full generations is a report, not a chat message,
 * and the prompts ask for the important part whole instead.
 *
 * ⚠️ COSTS A SECOND CALL, and $messages is replayed in full — including any
 * attachment on the user turn. Blocks 1 and 2 are unchanged, so the cached
 * prefix still hits and the marginal cost is the tail; but a truncated answer
 * to a question carrying a photo re-sends that photo. Acceptable because
 * truncation is rare and the alternative is a broken reply, not because it is
 * free.
 *
 * The two calls are recorded as ONE ledger row: see TokenUsage::plus().
 */
trait FinishesTruncatedAnswers
{
    /**
     * @param  array<int, Message>  $messages  Exactly what produced $first.
     * @param  array<string, mixed>  $options
     */
    private function finished(
        array $messages,
        LlmResponse $first,
        string $role = 'content',
        array $options = [],
    ): LlmResponse {
        if (! $first->wasTruncated() || trim($first->content) === '') {
            return $first;
        }

        // The partial reply goes back as the assistant's own turn, which is
        // what makes "sigue" mean something: the model reads its own unfinished
        // sentence rather than being asked to guess where it was.
        $continuation = [
            ...$messages,
            Message::assistant($first->content),
            Message::user(
                'Se cortó tu respuesta. Continúa exactamente donde quedaste, sin saludar, '
                .'sin repetir lo que ya escribiste y sin volver a introducir el tema.'
            ),
        ];

        try {
            $second = $this->client->complete($continuation, $role, $options);
        } catch (\Throwable) {
            // A failed continuation must not lose the half we already paid for.
            // Truncated is worse than whole; nothing is worse than truncated.
            return $first;
        }

        return new LlmResponse(
            content: rtrim($first->content).$this->joint($first->content).ltrim($second->content),
            model: $second->model,
            usage: $first->usage->plus($second->usage),
            finishReason: $second->finishReason,
            latencyMs: $first->latencyMs + $second->latencyMs,
        );
    }

    /**
     * What goes between the two halves.
     *
     * Nothing when the break landed mid-word — "responsa" + "ble" must not
     * become "responsa ble". A space otherwise, since the model was told not to
     * repeat itself and starts its half with a word rather than a newline.
     */
    private function joint(string $first): string
    {
        return preg_match('/\p{L}$/u', rtrim($first)) === 1 ? '' : ' ';
    }
}
