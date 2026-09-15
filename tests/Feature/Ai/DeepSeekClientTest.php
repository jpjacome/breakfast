<?php

declare(strict_types=1);

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\InsufficientBalance;
use App\Services\Ai\Exceptions\InvalidRequest;
use App\Services\Ai\Exceptions\ProviderUnavailable;
use App\Services\Ai\Exceptions\RateLimited;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.providers.deepseek.api_key', 'sk-test');
    config()->set('ai.limits.retries', 1);
    config()->set('ai.limits.retry_delay_ms', 0);
});

function fakeCompletion(array $overrides = []): array
{
    return array_replace_recursive([
        'model' => 'deepseek-v4-pro',
        'choices' => [[
            'message' => ['content' => 'Vuestro tono es conversacional y claro.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 10_000,
            'completion_tokens' => 500,
            'prompt_cache_hit_tokens' => 9_800,
            'prompt_cache_miss_tokens' => 200,
        ],
    ], $overrides);
}

it('sends a well-formed request and parses the response', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(fakeCompletion())]);

    $response = app(LlmClient::class)->complete([Message::user('¿Cuál es nuestro tono?')]);

    expect($response->content)->toBe('Vuestro tono es conversacional y claro.')
        ->and($response->model)->toBe('deepseek-v4-pro')
        ->and($response->usage->cacheHitTokens)->toBe(9_800)
        ->and($response->wasTruncated())->toBeFalse();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer sk-test')
            && str_contains($request->url(), '/chat/completions')
            && $body['model'] === 'deepseek-v4-pro'
            && $body['messages'][0]['role'] === 'user';
    });
});

it('sends reasoning_effort to the reasoning model only', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(fakeCompletion())]);

    app(LlmClient::class)->complete([Message::user('hola')], 'content');

    Http::assertSent(fn ($request) => ($request->data()['reasoning_effort'] ?? null) === 'high');
});

it('omits reasoning_effort for the non-reasoning utility model', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(
        fakeCompletion(['model' => 'deepseek-v4-flash'])
    )]);

    app(LlmClient::class)->complete([Message::user('hola')], 'utility');

    Http::assertSent(fn ($request) => ! array_key_exists('reasoning_effort', $request->data()));
});

it('requests json mode and decodes the payload', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(fakeCompletion([
        'choices' => [['message' => ['content' => '{"pilares":["educar","inspirar"]}']]],
    ]))]);

    $response = app(LlmClient::class)->json([Message::user('Dame los pilares en JSON')]);

    expect($response->toArray())->toBe(['pilares' => ['educar', 'inspirar']]);

    Http::assertSent(fn ($request) => $request->data()['response_format']['type'] === 'json_object');
});

it('decodes json even when the model wraps it in a markdown fence', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(fakeCompletion([
        'choices' => [['message' => ['content' => "```json\n{\"ok\":true}\n```"]]],
    ]))]);

    expect(app(LlmClient::class)->json([Message::user('x')])->toArray())->toBe(['ok' => true]);
});

it('flags a truncated response instead of returning half an answer', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(fakeCompletion([
        'choices' => [['finish_reason' => 'length']],
    ]))]);

    expect(app(LlmClient::class)->complete([Message::user('x')])->wasTruncated())->toBeTrue();
});

it('maps a 429 to a retryable RateLimited exception', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(
        ['error' => ['message' => 'too many requests']], 429
    )]);

    try {
        app(LlmClient::class)->complete([Message::user('x')]);
        expect()->fail('Expected RateLimited.');
    } catch (RateLimited $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

it('maps a 500 to a retryable ProviderUnavailable exception', function () {
    Http::fake(['api.deepseek.com/*' => Http::response('upstream error', 500)]);

    try {
        app(LlmClient::class)->complete([Message::user('x')]);
        expect()->fail('Expected ProviderUnavailable.');
    } catch (ProviderUnavailable $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

it('maps a 401 to a non-retryable InvalidRequest exception', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

    try {
        app(LlmClient::class)->complete([Message::user('x')]);
        expect()->fail('Expected InvalidRequest.');
    } catch (InvalidRequest $e) {
        expect($e->isRetryable())->toBeFalse()
            ->and($e->status)->toBe(401);
    }
});

it('maps a 402 to InsufficientBalance so it can be alerted on separately', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(
        ['error' => ['message' => 'Insufficient Balance']], 402
    )]);

    try {
        app(LlmClient::class)->complete([Message::user('x')]);
        expect()->fail('Expected InsufficientBalance.');
    } catch (InsufficientBalance $e) {
        // Not retryable: retrying a broke account just burns latency.
        expect($e->isRetryable())->toBeFalse()
            ->and($e->userMessage())->not->toContain('balance');
    }
});

it('fails clearly when the api key is missing', function () {
    config()->set('ai.providers.deepseek.api_key', null);
    Http::fake();

    expect(fn () => app(LlmClient::class)->complete([Message::user('x')]))
        ->toThrow(InvalidRequest::class, 'DEEPSEEK_API_KEY is not set');

    Http::assertNothingSent();
});

it('never leaks provider internals in the user-facing message', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'internal trace']], 500)]);

    try {
        app(LlmClient::class)->complete([Message::user('x')]);
    } catch (ProviderUnavailable $e) {
        expect($e->userMessage())->not->toContain('internal trace');
    }
});
