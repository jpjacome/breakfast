<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * JSON-mode response could not be decoded, or was truncated mid-object.
 *
 * DeepSeek's JSON mode guarantees parseable output but not a given shape, so
 * callers retry once with the validation errors fed back before failing.
 */
final class InvalidJsonResponse extends LlmException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
