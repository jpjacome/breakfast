<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Providers\OpenAiCompatibleClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds the configured provider to the LlmClient contract.
 *
 * To add a provider: implement LlmClient, add a config('ai.providers.*') entry
 * and a case below. Nothing else in the application changes.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, function (): LlmClient {
            $provider = (string) config('ai.provider');
            $config = config("ai.providers.{$provider}");

            if (! is_array($config)) {
                throw new InvalidArgumentException(
                    "AI provider [{$provider}] has no configuration in config/ai.php."
                );
            }

            return match ($provider) {
                'deepseek', 'openrouter' => new OpenAiCompatibleClient($config),
                default => throw new InvalidArgumentException(
                    "Unsupported AI provider [{$provider}]."
                ),
            };
        });
    }
}
