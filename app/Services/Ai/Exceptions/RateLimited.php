<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Provider returned 429. Retryable after a delay.
 */
final class RateLimited extends LlmException
{
    public function __construct(
        string $message = 'Rate limited by the AI provider.',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function userMessage(): string
    {
        return 'Hay mucha demanda en este momento. Vuelve a intentarlo en un minuto.';
    }
}
