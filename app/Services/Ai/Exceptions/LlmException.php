<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Base for every failure originating in the AI layer.
 *
 * Providers normalise their own error shapes into these, so consuming code
 * (jobs, Livewire components) never catches a Guzzle or provider-specific
 * exception and never has to know which provider is configured.
 */
class LlmException extends RuntimeException
{
    /**
     * Whether retrying the identical request could plausibly succeed.
     * Jobs use this to decide between release() and fail().
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * Message safe to show an end user. Never leaks provider internals.
     */
    public function userMessage(): string
    {
        return 'No pudimos generar la respuesta en este momento. Inténtalo de nuevo en unos minutos.';
    }
}
