<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * 5xx or a transport failure. Retryable.
 */
final class ProviderUnavailable extends LlmException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
