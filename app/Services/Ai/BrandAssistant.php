<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Concerns\FinishesTruncatedAnswers;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\BrandContext;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;

/**
 * The public face of the AI layer.
 *
 * This is what the client profile / portal calls. Everything below it —
 * provider, prompt assembly, usage accounting — is an implementation detail.
 *
 * Typical use once the client profile exists:
 *
 *     $context = BrandContext::make(
 *         clientId:   $client->id,
 *         clientName: $client->name,
 *             ->pluck('body', 'title')   // or ->mapWithKeys(...) from disk
 *             ->all(),
 *     );
 *
 *     foreach ($assistant->streamAnswer($context, $question, $user->id) as $delta) {
 *         $this->stream(to: 'answer', content: $delta, replace: false);
 *     }
 */
final class BrandAssistant
{
    use FinishesTruncatedAnswers;

    public function __construct(
        private readonly LlmClient $client,
        private readonly BrandContextBuilder $builder,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Blocking answer. Use for jobs and tests; prefer streamAnswer() in the UI.
     *
     * @param  array<int, Message>  $history
     * @param  string|null  $speaker  Who is asking, for the greeting. A NAME,
     *                                not a User: BrandContextRepository is the
     *                                only class in this layer that touches
     *                                Eloquent, and this one does not need to.
     *
     * @throws LlmException
     */
    public function answer(
        BrandContext $context,
        string $question,
        ?int $userId = null,
        array $history = [],
        array $attachments = [],
        ?string $speaker = null,
    ): LlmResponse {
        $messages = $history === [] && $attachments === []
            ? $this->builder->forQuestion($context, $question, $speaker)
            : $this->builder->forConversation($context, $history, $question, $attachments, $speaker);

        try {
            // finished(): a reply cut off at the token ceiling gets one more
            // call to complete itself, rather than reaching the client as a
            // promise with nothing after it. See the trait.
            $response = $this->finished($messages, $this->client->complete($messages));
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $context->clientId,
                userId: $userId,
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $context->clientId,
            userId: $userId,
            contextFingerprint: $context->fingerprint(),
        );

        return $response;
    }

    /**
     * Streaming answer for the UI. Yields text deltas; the assembled
     * LlmResponse is available from the generator's return value.
     *
     * Usage is recorded once the stream completes — DeepSeek reports token
     * counts on the final SSE frame, so it is not available before then.
     *
     * @param  array<int, Message>  $history
     * @return \Generator<int, string, void, LlmResponse>
     *
     * @throws LlmException
     */
    public function streamAnswer(
        BrandContext $context,
        string $question,
        ?int $userId = null,
        array $history = [],
        array $attachments = [],
        ?string $speaker = null,
    ): \Generator {
        $messages = $history === []
            ? $this->builder->forQuestion($context, $question, $speaker)
            : $this->builder->forConversation($context, $history, $question, $attachments, $speaker);

        try {
            $response = yield from $this->client->stream($messages);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $context->clientId,
                userId: $userId,
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $context->clientId,
            userId: $userId,
            contextFingerprint: $context->fingerprint(),
        );

        return $response;
    }

    /**
     * Connectivity and credential check. Returns a small diagnostic array
     * rather than throwing — intended for a health endpoint or an artisan
     * command, so an admin can verify the integration without a real question.
     *
     * @return array{ok: bool, model?: string, latency_ms?: int, error?: string}
     */
    public function healthCheck(): array
    {
        try {
            $response = $this->client->complete(
                [Message::user('Responde únicamente con la palabra: ok')],
                'utility',
                ['max_tokens' => 16, 'temperature' => 0.0],
            );

            return [
                'ok' => true,
                'model' => $response->model,
                'latency_ms' => $response->latencyMs,
            ];
        } catch (LlmException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
