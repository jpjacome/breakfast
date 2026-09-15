<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Brand\DeliverableSchema;
use Illuminate\Console\Command;

/**
 * Dumps the 48 entregables as JSON.
 *
 *   php artisan entregables:schema                       # to the screen
 *   php artisan entregables:schema --path=schema.json    # to a file
 *   php artisan entregables:schema --prompt              # the system-prompt version
 *
 * This is the artefact shared with the Breakfast team so everyone works from
 * the same list in the same order. Regenerate it after touching
 * DeliverableItem instead of editing any copy by hand — a hand-edited copy is
 * a second definition of the taxonomy, and then there are two.
 */
final class DeliverableSchemaExport extends Command
{
    protected $signature = 'entregables:schema {--path= : Write to this file instead of stdout}
                                               {--prompt : Emit the compact prompt block rather than JSON}';

    protected $description = 'Export the 48 entregables from App\Enums\DeliverableItem';

    public function handle(DeliverableSchema $schema): int
    {
        $output = $this->option('prompt') ? $schema->promptBlock() : $schema->toJson();
        $path = $this->option('path');

        if (! $path) {
            $this->line($output);

            return self::SUCCESS;
        }

        $absolute = str_starts_with((string) $path, '/') || preg_match('/^[A-Za-z]:/', (string) $path)
            ? (string) $path
            : base_path((string) $path);

        if (file_put_contents($absolute, $output) === false) {
            $this->error("Could not write to {$absolute}.");

            return self::FAILURE;
        }

        $this->info(sprintf('%d entregables escritos en %s.', count($schema->toArray()), $absolute));

        return self::SUCCESS;
    }
}
