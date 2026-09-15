<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What OpenRouter says about the account itself: bought, spent, left.
 *
 * This is the only figure in the app that is not derived from our own ledger.
 * The ledger answers "what did this brand's questions cost"; this answers
 * "how much is left before everything stops", which no amount of local
 * accounting can tell you — somebody topping up or spending from another app
 * moves it without a row here.
 *
 * IMPORTANT: /credits needs a MANAGEMENT key, not the inference key that
 * serves chat completions. Sending OPENROUTER_API_KEY here returns 403 "Only
 * management keys can perform this operation". Create one in the OpenRouter
 * dashboard and put it in OPENROUTER_MANAGEMENT_KEY; leave it unset and the
 * dashboard simply omits the balance rather than showing an error.
 */
final class OpenRouterAccount
{
    /** Long enough that a dashboard refresh is free; short enough to notice a drain. */
    private const CACHE_MINUTES = 10;

    /**
     * @return array{purchased: float, spent: float, remaining: float}|null
     *                                                                      Null when no management key is configured or the call failed.
     */
    public function credits(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember(
            'openrouter.credits',
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetch(),
        );
    }

    public function isConfigured(): bool
    {
        return is_string($this->managementKey()) && $this->managementKey() !== '';
    }

    /** @return array{purchased: float, spent: float, remaining: float}|null */
    private function fetch(): ?array
    {
        try {
            $response = Http::baseUrl($this->baseUri())
                ->withToken((string) $this->managementKey())
                ->acceptJson()
                ->connectTimeout(5)
                // Short on purpose: this is decoration on a page that must
                // render. It is never worth making the dashboard wait.
                ->timeout(8)
                ->get('/credits');
        } catch (\Throwable $e) {
            Log::warning('Could not reach OpenRouter for the credit balance.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OpenRouter refused the credits request.', [
                'status' => $response->status(),
                // 403 here almost always means an inference key was used.
                'hint' => $response->status() === 403
                    ? 'OPENROUTER_MANAGEMENT_KEY must be a management key, not the inference key.'
                    : null,
            ]);

            return null;
        }

        $purchased = (float) $response->json('data.total_credits', 0);
        $spent = (float) $response->json('data.total_usage', 0);

        return [
            // Credits are denominated 1:1 in USD, so these are dollars.
            'purchased' => $purchased,
            'spent' => $spent,
            'remaining' => $purchased - $spent,
        ];
    }

    private function managementKey(): ?string
    {
        return config('ai.providers.openrouter.management_key');
    }

    private function baseUri(): string
    {
        return rtrim((string) config('ai.providers.openrouter.base_uri'), '/');
    }
}
