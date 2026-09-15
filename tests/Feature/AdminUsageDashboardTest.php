<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('openrouter.credits');
    config()->set('ai.providers.openrouter.management_key', null);
    $this->actingAs(User::factory()->admin()->create());
});

function ledgerRow(array $overrides = []): void
{
    DB::table('ai_usage_logs')->insert([
        'provider' => 'openrouter',
        'model' => 'google/gemini-3.5-flash-lite',
        'operation' => 'question',
        'prompt_tokens' => 10_000,
        'completion_tokens' => 500,
        'cache_hit_tokens' => 8_000,
        'cache_miss_tokens' => 2_000,
        'cost_micro_usd' => 4_200,
        'cost_is_reported' => true,
        'latency_ms' => 900,
        'succeeded' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('shows what the ai has cost', function () {
    ledgerRow(['cost_micro_usd' => 1_250_000]);

    $this->get(route('admin.home'))
        ->assertOk()
        ->assertSee('uso de ia')
        ->assertSee('$1.25')
        ->assertSee('google/gemini-3.5-flash-lite');
});

it('calls the total an estimate only when it is one', function () {
    ledgerRow(['cost_is_reported' => true]);
    $this->get(route('admin.home'))->assertDontSee('estimado');

    // One DeepSeek row, priced from our table rather than reported.
    ledgerRow(['cost_is_reported' => false, 'model' => 'deepseek-v4-pro']);
    $this->get(route('admin.home'))->assertSee('estimado');
});

it('renders on an empty ledger', function () {
    $this->get(route('admin.home'))
        ->assertOk()
        ->assertSee('Todavía no hay consultas registradas', escape: false);
});

it('shows the openrouter balance when a management key is configured', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');

    Http::fake(['openrouter.ai/*' => Http::response([
        'data' => ['total_credits' => 50.0, 'total_usage' => 12.34],
    ])]);

    ledgerRow();

    $this->get(route('admin.home'))
        ->assertOk()
        ->assertSee('Saldo OpenRouter')
        ->assertSee('37.66');
});

it('omits the balance rather than failing when there is no management key', function () {
    Http::fake();
    ledgerRow();

    $this->get(route('admin.home'))
        ->assertOk()
        ->assertDontSee('Saldo OpenRouter');

    Http::assertNothingSent();
});

it('still renders when openrouter is unreachable', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');
    Http::fake(fn () => throw new ConnectionException('timed out'));

    ledgerRow();

    // The balance is decoration; the dashboard is not allowed to depend on it.
    $this->get(route('admin.home'))
        ->assertOk()
        ->assertDontSee('Saldo OpenRouter');
});
