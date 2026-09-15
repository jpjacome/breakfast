<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\BrandAssistant;
use Illuminate\Console\Command;

/**
 * Verifies the AI integration end to end without needing any UI.
 *
 *   php artisan ai:health
 */
final class AiHealthCheck extends Command
{
    protected $signature = 'ai:health';

    protected $description = 'Verify connectivity and credentials for the configured AI provider';

    public function handle(BrandAssistant $assistant): int
    {
        $provider = config('ai.provider');

        $this->line("Provider: <options=bold>{$provider}</>");
        $this->line('Model:    '.config("ai.providers.{$provider}.models.content"));

        if (blank(config("ai.providers.{$provider}.api_key"))) {
            $keyName = config("ai.providers.{$provider}.api_key_env", 'the API key');
            $this->error("No API key configured. Set {$keyName} in .env.");

            return self::FAILURE;
        }

        $this->line('Sending a minimal test request...');

        $result = $assistant->healthCheck();

        if ($result['ok'] === true) {
            $this->info("OK — {$result['model']} responded in {$result['latency_ms']}ms.");

            return self::SUCCESS;
        }

        $this->error('Failed: '.$result['error']);

        return self::FAILURE;
    }
}
