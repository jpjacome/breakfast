<?php

declare(strict_types=1);

use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\TokenUsage;
use App\Services\Ai\Exceptions\RateLimited;
use App\Services\Ai\UsageRecorder;
use Illuminate\Support\Facades\DB;

it('records usage and computes cost from the configured price table', function () {
    $response = new LlmResponse(
        content: 'ok',
        model: 'deepseek-v4-pro',
        usage: new TokenUsage(
            promptTokens: 10_000,
            completionTokens: 500,
            cacheHitTokens: 9_800,
            cacheMissTokens: 200,
        ),
        finishReason: 'stop',
        latencyMs: 4_200,
    );

    app(UsageRecorder::class)->record($response, clientId: 7, userId: 3);

    $row = DB::table('ai_usage_logs')->first();

    expect($row->client_id)->toBe(7)
        ->and($row->model)->toBe('deepseek-v4-pro')
        ->and($row->cache_hit_tokens)->toBe(9_800)
        ->and($row->latency_ms)->toBe(4_200)
        ->and((bool) $row->succeeded)->toBeTrue();

    //   200 miss  @ $0.435/M    =  87.000 micro-USD
    // 9,800 hit   @ $0.003625/M =  35.525 micro-USD
    //   500 out   @ $0.87/M     = 435.000 micro-USD
    //                             -------
    //                             557.525 -> 558 micro-USD (~$0.00056 per question)
    expect($row->cost_micro_usd)->toBe(558);
});

it('shows the cost impact of losing the prefix cache', function () {
    $recorder = app(UsageRecorder::class);

    $cached = $recorder->costMicroUsd(new LlmResponse('ok', 'deepseek-v4-pro',
        new TokenUsage(10_000, 500, 9_800, 200)));

    $uncached = $recorder->costMicroUsd(new LlmResponse('ok', 'deepseek-v4-pro',
        new TokenUsage(10_000, 500, 0, 10_000)));

    // Roughly 8x more expensive per question with a cold prefix. This test is
    // documentation as much as assertion: it is why BrandContextBuilder is strict.
    expect($uncached)->toBeGreaterThan($cached * 5);
});

it('records failures so error spikes are visible', function () {
    app(UsageRecorder::class)->recordFailure(
        new RateLimited('rate limited'),
        clientId: 7,
        model: 'deepseek-v4-pro',
    );

    $row = DB::table('ai_usage_logs')->first();

    expect((bool) $row->succeeded)->toBeFalse()
        ->and($row->failure_reason)->toContain('RateLimited');
});

it('does not throw when the model has no price table', function () {
    $cost = app(UsageRecorder::class)->costMicroUsd(
        new LlmResponse('ok', 'some-unreleased-model', new TokenUsage(100, 100))
    );

    expect($cost)->toBe(0);
});

it('reports the cache hit rate', function () {
    expect((new TokenUsage(10_000, 500, 9_800, 200))->cacheHitRate())->toBe(0.98)
        ->and((new TokenUsage(0, 0, 0, 0))->cacheHitRate())->toBe(0.0);
});

/* -------------------------------------------------------------------------
 | Provider-reported cost
 |
 | OpenRouter returns what it actually charged on every completion, and its
 | credits are USD. That figure beats any price table we keep, because it
 | already knows the real cache split and which model the router picked.
 ------------------------------------------------------------------------- */

it('reads the cost OpenRouter reports on the usage object', function () {
    $usage = TokenUsage::fromApi([
        'prompt_tokens' => 10_000,
        'completion_tokens' => 500,
        'prompt_tokens_details' => ['cached_tokens' => 8_000],
        'cost' => 0.0042,
    ]);

    expect($usage->reportedCostUsd)->toBe(0.0042)
        // OpenRouter spells the cache read differently from DeepSeek; reading
        // only DeepSeek's key reported a permanent 0% hit rate here.
        ->and($usage->cacheHitTokens)->toBe(8_000)
        ->and($usage->cacheMissTokens)->toBe(2_000);
});

it('leaves the reported cost null when the provider does not send one', function () {
    // DeepSeek's shape: no `cost` key at all.
    $usage = TokenUsage::fromApi([
        'prompt_tokens' => 100,
        'completion_tokens' => 10,
        'prompt_cache_hit_tokens' => 64,
        'prompt_cache_miss_tokens' => 36,
    ]);

    // Null, not 0.0 — a free model genuinely costing nothing is a real answer
    // and must not look like "the provider said nothing".
    expect($usage->reportedCostUsd)->toBeNull()
        ->and($usage->cacheHitTokens)->toBe(64);
});

it('bills the reported cost rather than the price table when there is one', function () {
    $reported = new LlmResponse('ok', 'google/gemini-3.5-flash-lite', new TokenUsage(
        promptTokens: 10_000,
        completionTokens: 500,
        cacheHitTokens: 8_000,
        cacheMissTokens: 2_000,
        reportedCostUsd: 0.0042,
    ));

    app(UsageRecorder::class)->record($reported, clientId: 7);

    $row = DB::table('ai_usage_logs')->first();

    expect($row->cost_micro_usd)->toBe(4_200)
        ->and((bool) $row->cost_is_reported)->toBeTrue();
});

it('falls back to the price table and says so when nothing was reported', function () {
    app(UsageRecorder::class)->record(
        new LlmResponse('ok', 'deepseek-v4-pro', new TokenUsage(10_000, 500, 9_800, 200))
    );

    $row = DB::table('ai_usage_logs')->first();

    expect($row->cost_micro_usd)->toBe(558)
        ->and((bool) $row->cost_is_reported)->toBeFalse();
});
