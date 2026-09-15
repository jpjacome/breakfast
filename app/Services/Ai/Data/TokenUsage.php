<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Token accounting for one request.
 *
 * DeepSeek splits prompt tokens into cache hits and misses:
 *   prompt_cache_hit_tokens  — matched a cached prefix, ~150x cheaper
 *   prompt_cache_miss_tokens — billed at full input rate
 *
 * Caching is automatic and prefix-based; there are no cache markers to set.
 * A persistently zero cacheHitTokens across repeated requests for the same
 * client means something upstream is varying the prompt prefix.
 *
 * The two providers spell the cache read differently — DeepSeek uses
 * prompt_cache_hit_tokens, OpenRouter (and the OpenAI format generally) uses
 * prompt_tokens_details.cached_tokens. Both are read below, because reading
 * only one of them silently reports a 0% hit rate on the other provider.
 */
final readonly class TokenUsage
{
    /**
     * @param  float|null  $reportedCostUsd  What the provider says it charged,
     *                                       when it says so at all. See below.
     */
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cacheHitTokens = 0,
        public int $cacheMissTokens = 0,
        public ?float $reportedCostUsd = null,
    ) {}

    /**
     * @param  array<string, mixed>  $usage  Raw `usage` object from the API.
     */
    public static function fromApi(array $usage): self
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);

        $hit = (int) ($usage['prompt_cache_hit_tokens']
            ?? $usage['prompt_tokens_details']['cached_tokens']
            ?? 0);

        // Providers that don't report a split bill everything as a miss.
        $miss = array_key_exists('prompt_cache_miss_tokens', $usage)
            ? (int) $usage['prompt_cache_miss_tokens']
            : max(0, $prompt - $hit);

        return new self(
            promptTokens: $prompt,
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            cacheHitTokens: $hit,
            cacheMissTokens: $miss,
            reportedCostUsd: self::readCost($usage),
        );
    }

    /**
     * The provider's own figure for what this request cost, in USD.
     *
     * OpenRouter returns `cost` on every response — no request parameter, the
     * old `usage: {include: true}` opt-in is deprecated — and its credits are
     * denominated 1:1 in US dollars, so the number is literally money off the
     * account rather than an estimate from a price table.
     *
     * DeepSeek does not report cost, so this stays null there and the price
     * table in config/ai.php remains the only answer. Null and 0.0 are
     * different: a free-tier model genuinely costs zero.
     */
    private static function readCost(array $usage): ?float
    {
        return isset($usage['cost']) && is_numeric($usage['cost'])
            ? (float) $usage['cost']
            : null;
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * Two calls' usage as one, for an answer that took two requests.
     *
     * ONE ANSWER IS ONE LEDGER ROW. When a reply is cut off at the token
     * ceiling and finished by a second call, both calls were paid for — see
     * FinishesTruncatedAnswers — and recording only the second would quietly
     * under-report every truncated answer.
     *
     * reportedCostUsd stays null unless at least one side reported: null means
     * "the provider did not say", and turning that into 0.0 by addition would
     * claim a free request. Where only one side reported, that figure is used
     * plus nothing, which is the closest honest answer available.
     */
    public function plus(self $other): self
    {
        $cost = $this->reportedCostUsd === null && $other->reportedCostUsd === null
            ? null
            : ($this->reportedCostUsd ?? 0.0) + ($other->reportedCostUsd ?? 0.0);

        return new self(
            promptTokens: $this->promptTokens + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            cacheHitTokens: $this->cacheHitTokens + $other->cacheHitTokens,
            cacheMissTokens: $this->cacheMissTokens + $other->cacheMissTokens,
            reportedCostUsd: $cost,
        );
    }

    /**
     * Share of prompt tokens served from cache, 0.0–1.0.
     * Surfaced in the admin usage dashboard: a healthy repeat-question
     * workload should sit well above 0.5.
     */
    public function cacheHitRate(): float
    {
        return $this->promptTokens > 0
            ? $this->cacheHitTokens / $this->promptTokens
            : 0.0;
    }

    /**
     * Cost in USD given a price table of USD per 1M tokens.
     *
     * @param  array{input: float, cache_hit: float, output: float}  $prices
     */
    public function costUsd(array $prices): float
    {
        return (
            $this->cacheMissTokens * $prices['input']
            + $this->cacheHitTokens * $prices['cache_hit']
            + $this->completionTokens * $prices['output']
        ) / 1_000_000;
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_hit_tokens' => $this->cacheHitTokens,
            'cache_miss_tokens' => $this->cacheMissTokens,
            'cache_hit_rate' => round($this->cacheHitRate(), 4),
            'reported_cost_usd' => $this->reportedCostUsd,
        ];
    }
}
