<?php

declare(strict_types=1);

use App\Services\Ai\OpenRouterAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('openrouter.credits');
    config()->set('ai.providers.openrouter.base_uri', 'https://openrouter.ai/api/v1');
});

it('reads the balance and works out what is left', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');

    Http::fake(['openrouter.ai/api/v1/credits' => Http::response([
        'data' => ['total_credits' => 50.0, 'total_usage' => 12.345],
    ])]);

    expect(app(OpenRouterAccount::class)->credits())->toBe([
        'purchased' => 50.0,
        'spent' => 12.345,
        'remaining' => 37.655,
    ]);
});

it('sends the management key, not the inference key', function () {
    config()->set('ai.providers.openrouter.api_key', 'sk-inference');
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');

    Http::fake(['openrouter.ai/*' => Http::response(['data' => ['total_credits' => 1, 'total_usage' => 0]])]);

    app(OpenRouterAccount::class)->credits();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-mgmt-test'));
});

it('stays quiet when no management key is configured', function () {
    config()->set('ai.providers.openrouter.management_key', null);

    Http::fake();

    // The balance is optional decoration — an unset key is a normal state,
    // not an error, and must not cost a request.
    expect(app(OpenRouterAccount::class)->credits())->toBeNull();
    Http::assertNothingSent();
});

it('returns null rather than breaking the dashboard when the key is refused', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-wrong-kind');

    // What an inference key actually gets back from /credits.
    Http::fake(['openrouter.ai/*' => Http::response([
        'error' => ['message' => 'Only management keys can perform this operation'],
    ], 403)]);

    expect(app(OpenRouterAccount::class)->credits())->toBeNull();
});

it('returns null when openrouter cannot be reached', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');

    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(OpenRouterAccount::class)->credits())->toBeNull();
});

it('caches the balance so a dashboard refresh is free', function () {
    config()->set('ai.providers.openrouter.management_key', 'sk-mgmt-test');

    Http::fake(['openrouter.ai/*' => Http::response([
        'data' => ['total_credits' => 20.0, 'total_usage' => 5.0],
    ])]);

    $account = app(OpenRouterAccount::class);
    $account->credits();
    $account->credits();

    Http::assertSentCount(1);
});
