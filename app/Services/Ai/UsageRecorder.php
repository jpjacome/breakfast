<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Exceptions\LlmException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes the ai_usage_logs ledger.
 *
 * Uses the query builder rather than an Eloquent model so it stays independent
 * of the models being built in parallel. Swap to a model later if useful; the
 * table shape is the contract.
 *
 * Recording never throws. Losing an audit row must not fail a generation the
 * user already paid for and is watching stream in.
 */
final class UsageRecorder
{
    public function record(
        LlmResponse $response,
        ?int $clientId = null,
        ?int $userId = null,
        string $operation = 'question',
        ?string $contextFingerprint = null,
    ): void {
        $this->write([
            'client_id' => $clientId,
            'user_id' => $userId,
            'provider' => config('ai.provider'),
            'model' => $response->model,
            'operation' => $operation,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'cache_hit_tokens' => $response->usage->cacheHitTokens,
            'cache_miss_tokens' => $response->usage->cacheMissTokens,
            'cost_micro_usd' => $this->costMicroUsd($response),
            'cost_is_reported' => $response->usage->reportedCostUsd !== null,
            'latency_ms' => $response->latencyMs,
            'succeeded' => true,
            'context_fingerprint' => $contextFingerprint,
        ]);
    }

    /**
     * Log a failed attempt. Failures still cost latency and sometimes tokens,
     * and a spike in one failure_reason is the cheapest early warning you get.
     */
    public function recordFailure(
        LlmException $exception,
        ?int $clientId = null,
        ?int $userId = null,
        string $operation = 'question',
        string $model = 'unknown',
    ): void {
        $this->write([
            'client_id' => $clientId,
            'user_id' => $userId,
            'provider' => config('ai.provider'),
            'model' => $model,
            'operation' => $operation,
            'succeeded' => false,
            'failure_reason' => mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 255),
        ]);
    }

    /**
     * Cost in integer micro-USD (1e-6 USD).
     *
     * The provider's own figure wins when there is one. OpenRouter reports
     * `cost` on every response and its credits are USD, so that number is the
     * actual charge — it already accounts for the real cache split, the model
     * the router actually picked, and any file-parsing surcharge, none of
     * which a static price table can know about.
     *
     * The table below is the fallback for providers that report nothing,
     * which today means DeepSeek.
     */
    public function costMicroUsd(LlmResponse $response): int
    {
        if ($response->usage->reportedCostUsd !== null) {
            return (int) round($response->usage->reportedCostUsd * 1_000_000);
        }

        $prices = config('ai.providers.'.config('ai.provider').".prices.{$response->model}");

        if (! is_array($prices)) {
            // Unknown model — record zero rather than guessing a price, and
            // make it loud so the config gets updated.
            Log::warning('No price table configured for AI model.', [
                'model' => $response->model,
                'provider' => config('ai.provider'),
            ]);

            return 0;
        }

        return (int) round($response->usage->costUsd($prices) * 1_000_000);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(array $row): void
    {
        try {
            DB::table('ai_usage_logs')->insert([
                ...$row,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write ai_usage_logs row.', [
                'error' => $e->getMessage(),
                'row' => $row,
            ]);
        }
    }
}
