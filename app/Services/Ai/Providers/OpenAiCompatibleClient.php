<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Data\TokenUsage;
use App\Services\Ai\Exceptions\InsufficientBalance;
use App\Services\Ai\Exceptions\InvalidRequest;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\ProviderUnavailable;
use App\Services\Ai\Exceptions\RateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Any provider that speaks the OpenAI chat-completions wire format.
 *
 * DeepSeek and OpenRouter (and therefore Kimi, served through it) all use the
 * same shape, so they share this one class — everything that differs between
 * them is a value in config/ai.php: base_uri, api_key, model ids, prices.
 * Adding a provider is a config entry plus a line in AiServiceProvider.
 *
 * Built on Laravel's HTTP client rather than an SDK: the surface we need is
 * small and it keeps composer.json untouched.
 *
 * Two behaviours worth knowing:
 *
 *  1. Context caching is AUTOMATIC and prefix-based. There is nothing to mark.
 *     Cache hits depend entirely on the prompt prefix being byte-identical
 *     between requests, which is why BrandContextBuilder is strict about
 *     ordering and about keeping volatile content in the final user turn.
 *     NOTE: not every host honours it — some bill all input at the uncached
 *     rate regardless. See the prices in config/ai.php.
 *
 *  2. reasoning_effort is only accepted by reasoning models. Sending it to a
 *     model that does not support it is rejected, so it is filtered per model
 *     via models_supporting_reasoning_effort.
 */
final class OpenAiCompatibleClient implements LlmClient
{
    /**
     * Pause before the one retry, so an overloaded provider gets a moment.
     *
     * Short on purpose: somebody is watching a spinner, and the failures worth
     * retrying at all are the ones that clear in a second or two.
     */
    private const RETRY_BACKOFF_MS = 800;

    /**
     * Below this many seconds left, do not bother retrying.
     *
     * A retry that cannot finish inside the budget is worse than the error it
     * was trying to avoid: the worker is held until the host kills it, and that
     * failure is invisible — it happens before Laravel's handler runs, so
     * nothing reaches laravel.log. See CLAUDE.md §3.
     */
    private const MIN_RETRY_SECONDS = 20;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /** Provider name for error messages — "DeepSeek", "OpenRouter". */
    private function label(): string
    {
        return (string) ($this->config['label'] ?? 'The AI provider');
    }

    /** The .env variable holding this provider's key, named in errors. */
    private function keyName(): string
    {
        return (string) ($this->config['api_key_env'] ?? 'The API key');
    }

    public function modelFor(string $role): string
    {
        $model = $this->config['models'][$role] ?? null;

        if (! is_string($model) || $model === '') {
            throw new InvalidRequest("No model configured for AI role [{$role}].");
        }

        return $model;
    }

    public function complete(array $messages, string $role = 'content', array $options = []): LlmResponse
    {
        $startedAt = microtime(true);

        $response = $this->send(
            $this->payload($messages, $role, $options)
        );

        return $this->toLlmResponse($response->json(), $startedAt);
    }

    public function json(array $messages, string $role = 'content', array $options = []): LlmResponse
    {
        return $this->complete($messages, $role, [
            ...$options,
            'response_format' => ['type' => 'json_object'],
            // JSON structure is a mechanical task; sampling variety only hurts.
            'temperature' => $options['temperature'] ?? 0.2,
        ]);
    }

    public function stream(array $messages, string $role = 'content', array $options = []): \Generator
    {
        $startedAt = microtime(true);

        $payload = $this->payload($messages, $role, $options);
        $payload['stream'] = true;
        // Ask for usage on the final SSE frame so cost can still be recorded.
        $payload['stream_options'] = ['include_usage' => true];

        $response = $this->send($payload, stream: true);

        $body = $response->toPsrResponse()->getBody();

        $content = '';
        $model = $payload['model'];
        $finishReason = null;
        $usage = new TokenUsage;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // SSE frames are newline-delimited; keep any partial tail buffered.
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    break 2;
                }

                $chunk = json_decode($data, true);

                if (! is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage = TokenUsage::fromApi($chunk['usage']);
                }

                $choice = $chunk['choices'][0] ?? null;

                if (! is_array($choice)) {
                    continue;
                }

                $finishReason = $choice['finish_reason'] ?? $finishReason;
                $delta = $choice['delta']['content'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    $content .= $delta;
                    yield $delta;
                }
            }
        }

        return new LlmResponse(
            content: $content,
            model: $model,
            usage: $usage,
            finishReason: $finishReason,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(array $messages, string $role, array $options): array
    {
        $model = $options['model'] ?? $this->modelFor($role);

        $payload = [
            'model' => $model,
            'messages' => array_map(
                static fn (Message $message): array => $message->toArray(),
                $messages,
            ),
            'max_tokens' => $options['max_tokens'] ?? config('ai.limits.max_tokens'),
            'temperature' => $options['temperature'] ?? $this->config['temperature'],
        ];

        // Only reasoning models accept this; others reject the request outright.
        $supportsEffort = in_array(
            $model,
            $this->config['models_supporting_reasoning_effort'] ?? [],
            strict: true,
        );

        if ($supportsEffort && ! empty($this->config['reasoning_effort'])) {
            $payload['reasoning_effort'] = $options['reasoning_effort']
                ?? $this->config['reasoning_effort'];
        }

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        // Pin how PDFs are parsed rather than letting the router choose. Only
        // sent when a request actually carries a file — an unused plugin
        // declaration is noise in every other request, and noise in the
        // cacheable part of a payload is a cache miss.
        if (! empty($this->config['pdf_engine']) && $this->carriesFile($messages)) {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => $this->config['pdf_engine']],
            ]];
        }

        return $payload;
    }

    /**
     * True when any message carries a file part.
     *
     * @param  array<int, Message>  $messages
     */
    private function carriesFile(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! is_array($message->content)) {
                continue;
            }

            foreach ($message->content as $part) {
                if (($part['type'] ?? null) === 'file') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws LlmException
     */
    private function send(array $payload, bool $stream = false): Response
    {
        try {
            $response = $this->request($stream)->post('/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable(
                'Could not reach the AI provider: '.$e->getMessage(),
                previous: $e,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        $error = $this->normaliseError($response);

        // ⚠️ ONE RETRY, AND ONLY FOR THE FAILURES THAT PASS ON THEIR OWN.
        //
        // Laravel's ->retry() above covers transport failures only; an HTTP 500
        // from the provider is a successful round trip carrying bad news, so it
        // never reached it. The beta review found exactly that: "el proveedor de
        // IA no respondió", and the same question answered fine when typed again
        // by hand. If a human retrying works, the machine should have.
        //
        // Deliberately narrow. A 4xx means the request itself is wrong and will
        // be just as wrong the second time; a 402 means the account is empty and
        // retrying spends nothing but wall clock. Both are excluded.
        //
        // The budget is the constraint that keeps this honest: this host kills a
        // request at ~180s (CLAUDE.md §3), so a second full-timeout attempt could
        // take the whole page down instead of the answer. secondsLeft() gives the
        // retry whatever is left of the budget and skips it when that is too
        // little to be worth the wait.
        if ($this->worthRetrying($error) && ! $stream) {
            $remaining = $this->secondsLeft();

            if ($remaining >= self::MIN_RETRY_SECONDS) {
                usleep(self::RETRY_BACKOFF_MS * 1000);

                try {
                    $retry = $this->request($stream)
                        ->timeout($remaining)
                        ->post('/chat/completions', $payload);
                } catch (ConnectionException) {
                    throw $error;
                }

                if ($retry->successful()) {
                    return $retry;
                }

                throw $this->normaliseError($retry);
            }
        }

        throw $error;
    }

    /** Failures that a second attempt might survive. */
    private function worthRetrying(LlmException $error): bool
    {
        return $error instanceof ProviderUnavailable || $error instanceof RateLimited;
    }

    /**
     * How much of this request's time budget is left, in whole seconds.
     *
     * Measured from the start of the PHP request rather than from the first
     * provider call, because what matters is the host's ceiling on the whole
     * thing, not on our part of it.
     */
    private function secondsLeft(): int
    {
        $budget = (int) config('ai.limits.timeout');
        $spent = (int) (microtime(true) - (float) (request()->server('REQUEST_TIME_FLOAT') ?: microtime(true)));

        return max(0, $budget - $spent);
    }

    private function request(bool $stream): PendingRequest
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new InvalidRequest(
                sprintf('%s is not set. Add it to .env — never commit it.', $this->keyName())
            );
        }

        $request = Http::baseUrl(rtrim((string) $this->config['base_uri'], '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout((int) config('ai.limits.connect_timeout'))
            ->timeout((int) config('ai.limits.timeout'))
            // Only transport-level failures are retried here; HTTP error codes
            // are classified in normaliseError() so 4xx never burns retries.
            ->retry(
                (int) config('ai.limits.retries'),
                (int) config('ai.limits.retry_delay_ms'),
                throw: false,
            );

        return $stream
            ? $request->withOptions(['stream' => true])
            : $request;
    }

    private function normaliseError(Response $response): LlmException
    {
        $status = $response->status();

        // A streamed body may not be readable as JSON; guard it.
        $message = rescue(
            fn () => (string) ($response->json('error.message') ?? $response->body()),
            'Unknown provider error.',
            report: false,
        );

        return match (true) {
            $status === 429 => new RateLimited(
                "{$this->label()} rate limit: {$message}",
                retryAfterSeconds: (int) $response->header('Retry-After') ?: null,
            ),
            $status >= 500 => new ProviderUnavailable("{$this->label()} {$status}: {$message}"),
            $status === 401 || $status === 403 => new InvalidRequest(
                "{$this->label()} rejected the API key ({$status}). Check {$this->keyName()}.",
                $status,
            ),
            // 402 takes down every client at once and is fixed by topping up
            // the account, not by changing code — worth its own alert.
            $status === 402 => new InsufficientBalance(
                "{$this->label()} account has insufficient balance: {$message}"
            ),
            default => new InvalidRequest("{$this->label()} {$status}: {$message}", $status),
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function toLlmResponse(?array $body, float $startedAt): LlmResponse
    {
        if (! is_array($body) || ! isset($body['choices'][0])) {
            throw new ProviderUnavailable(sprintf('Malformed response from %s: no choices returned.', $this->label()));
        }

        $choice = $body['choices'][0];

        return new LlmResponse(
            content: (string) ($choice['message']['content'] ?? ''),
            model: (string) ($body['model'] ?? 'unknown'),
            usage: TokenUsage::fromApi($body['usage'] ?? []),
            finishReason: $choice['finish_reason'] ?? null,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }
}
