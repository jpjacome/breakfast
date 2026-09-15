<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Services\Ai\Exceptions\InvalidJsonResponse;

final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public TokenUsage $usage,
        public ?string $finishReason = null,
        public int $latencyMs = 0,
    ) {}

    /**
     * True when the model stopped because it hit the token ceiling, meaning
     * `content` is cut off mid-thought. Check before persisting or displaying.
     */
    public function wasTruncated(): bool
    {
        return $this->finishReason === 'length';
    }

    /**
     * Decode a JSON-mode response.
     *
     * JSON mode guarantees parseable JSON, not a particular shape — validate
     * the returned array before it reaches the database.
     *
     * @return array<mixed>
     *
     * @throws InvalidJsonResponse
     */
    public function toArray(): array
    {
        if ($this->wasTruncated()) {
            throw new InvalidJsonResponse(
                'Model output was truncated before the JSON was complete. Raise max_tokens.'
            );
        }

        $decoded = json_decode($this->stripCodeFence($this->content), true);

        if (! is_array($decoded)) {
            throw new InvalidJsonResponse(
                'Model did not return a decodable JSON object: '.json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * JSON mode occasionally still wraps output in a markdown fence.
     */
    private function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;

        return trim(preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed);
    }
}
