<?php

declare(strict_types=1);

use App\Models\Client;
use App\Services\Ai\Data\UsageSummary;
use App\Services\Ai\UsageStatistics;
use Illuminate\Support\Facades\DB;

/** One ledger row, with sensible defaults so each test states only what it cares about. */
function usageRow(array $overrides = []): void
{
    DB::table('ai_usage_logs')->insert([
        'client_id' => null,
        'user_id' => null,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3.5-flash-lite',
        'operation' => 'question',
        'prompt_tokens' => 1_000,
        'completion_tokens' => 100,
        'cache_hit_tokens' => 800,
        'cache_miss_tokens' => 200,
        'cost_micro_usd' => 1_000,
        'cost_is_reported' => true,
        'latency_ms' => 500,
        'succeeded' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('totals spend and tokens over the window', function () {
    usageRow(['cost_micro_usd' => 1_500]);
    usageRow(['cost_micro_usd' => 2_500]);

    $summary = app(UsageStatistics::class)->summary(30);

    expect($summary->requests)->toBe(2)
        ->and($summary->costMicroUsd)->toBe(4_000)
        ->and($summary->totalTokens())->toBe(2_200)
        ->and($summary->usd())->toBe(0.004);
});

it('ignores rows older than the window', function () {
    usageRow(['cost_micro_usd' => 1_000]);
    usageRow(['cost_micro_usd' => 9_999, 'created_at' => now()->subDays(45)]);

    expect(app(UsageStatistics::class)->summary(30)->costMicroUsd)->toBe(1_000);
});

it('counts failures separately and keeps them out of the totals', function () {
    usageRow();
    usageRow([
        'succeeded' => false,
        'failure_reason' => 'RateLimited: slow down',
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cache_hit_tokens' => 0,
        'cost_micro_usd' => 0,
        'cost_is_reported' => false,
    ]);

    $summary = app(UsageStatistics::class)->summary(30);

    expect($summary->requests)->toBe(1)
        ->and($summary->failures)->toBe(1)
        // A failed call carries no tokens; it must not drag the rate down.
        ->and($summary->cacheHitRate())->toBe(0.8)
        ->and($summary->successRate())->toBe(0.5);
});

it('knows whether the total is real money or an estimate', function () {
    usageRow(['cost_is_reported' => true]);
    expect(app(UsageStatistics::class)->summary(30)->costIsExact())->toBeTrue();

    // One DeepSeek row priced from the table makes the whole window inexact.
    usageRow(['cost_is_reported' => false, 'model' => 'deepseek-v4-pro']);
    expect(app(UsageStatistics::class)->summary(30)->costIsExact())->toBeFalse();
});

it('has nothing to say about an empty ledger without dividing by zero', function () {
    $summary = app(UsageStatistics::class)->summary(30);

    expect($summary->requests)->toBe(0)
        ->and($summary->cacheHitRate())->toBe(0.0)
        ->and($summary->costIsExact())->toBeFalse()
        ->and($summary->formattedUsd())->toBe('$0.0000');
});

it('breaks spend down by model, dearest first', function () {
    usageRow(['model' => 'cheap-model', 'cost_micro_usd' => 500]);
    usageRow(['model' => 'dear-model', 'cost_micro_usd' => 8_000]);
    usageRow(['model' => 'cheap-model', 'cost_micro_usd' => 500]);

    $rows = app(UsageStatistics::class)->byModel(30);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->model)->toBe('dear-model')
        ->and((int) $rows[0]->cost_micro_usd)->toBe(8_000)
        ->and($rows[1]->model)->toBe('cheap-model')
        ->and((int) $rows[1]->requests)->toBe(2)
        ->and((int) $rows[1]->tokens)->toBe(2_200);
});

it('breaks spend down by brand and keeps roster-wide questions separate', function () {
    $client = Client::factory()->create(['name' => 'La Marca']);

    usageRow(['client_id' => $client->id, 'cost_micro_usd' => 3_000]);
    usageRow(['client_id' => null, 'cost_micro_usd' => 1_000]);

    $rows = app(UsageStatistics::class)->byClient(30);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->client_name)->toBe('La Marca')
        ->and((int) $rows[0]->cost_micro_usd)->toBe(3_000)
        // Asked across the whole roster: Breakfast's cost, not a brand's.
        ->and($rows[1]->client_name)->toBeNull();
});

it('formats sub-cent amounts without rounding them to nothing', function () {
    // The whole reason the ledger stores micro-USD: a question costs this much.
    expect(UsageSummary::formatMicroUsd(558))->toBe('$0.0006')
        ->and(UsageSummary::formatMicroUsd(4_200))->toBe('$0.0042')
        // Once it is worth reading as money, two decimals is what you want.
        ->and(UsageSummary::formatMicroUsd(12_500_000))->toBe('$12.50');
});
