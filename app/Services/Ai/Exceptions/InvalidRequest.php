<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * 4xx other than 429: malformed request, bad model id, bad or missing API key.
 * Never retryable — retrying an identical bad request just burns quota.
 */
final class InvalidRequest extends LlmException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
