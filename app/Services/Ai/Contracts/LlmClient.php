<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;

/**
 * Provider-agnostic LLM surface.
 *
 * Application code depends on this interface only. Swapping DeepSeek for
 * another provider means writing one new implementation and changing
 * config('ai.provider') — no call site changes.
 */
interface LlmClient
{
    /**
     * Single blocking completion. Use for short outputs and background work.
     *
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, string $role = 'content', array $options = []): LlmResponse;

    /**
     * Streaming completion. Yields text deltas as they arrive, then returns
     * the assembled LlmResponse (including usage) via getReturn().
     *
     * Required for anything user-facing: a long generation on a blocking
     * request will hit the HTTP timeout.
     *
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $options
     * @return \Generator<int, string, void, LlmResponse>
     */
    public function stream(array $messages, string $role = 'content', array $options = []): \Generator;

    /**
     * Completion constrained to a JSON object.
     *
     * DeepSeek offers JSON mode, not strict schema enforcement — valid JSON is
     * returned but its shape is not guaranteed. Callers MUST validate the
     * decoded payload before persisting it.
     *
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $options
     */
    public function json(array $messages, string $role = 'content', array $options = []): LlmResponse;

    /**
     * Concrete model id backing a logical role ('content' | 'utility').
     */
    public function modelFor(string $role): string;
}
