<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Ai\BrandContextRepository;
use Illuminate\Console\Command;

/**
 * Reports whether each client's uploaded context actually reaches the AI
 * layer as text. Temporary integration check between the admin upload flow
 * and the DeepSeek brand-context repository.
 */
class ContextProbe extends Command
{
    protected $signature = 'context:probe';

    protected $description = 'Show which clients have usable brand context';

    public function handle(BrandContextRepository $repo): int
    {
        $rows = [];

        foreach (Client::with('contextDocuments')->get() as $client) {
            $context = $repo->for($client);
            $unreadable = $repo->unreadableDocuments($client);

            $rows[] = [
                $client->name,
                $client->contextDocuments->count(),
                count($context->documents ?? []),
                count($unreadable),
                $context->isEmpty() ? 'NO' : 'sí',
            ];
        }

        $this->table(
            ['Marca', 'Archivos', 'Legibles', 'Ilegibles', '¿Contexto usable?'],
            $rows,
        );

        return self::SUCCESS;
    }
}
