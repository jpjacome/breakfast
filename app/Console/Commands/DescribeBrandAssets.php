<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DescribeBrandAsset;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Read the images already sitting in brands' folders.
 *
 * ⚠️ NOT SCHEDULED, AND IT MUST NOT BE. Uploads describe themselves; this is
 * for the files that were filed before that existed, and for the ones an upload
 * skipped because it ran out of its budget. Correctness never depends on it
 * having run — an undescribed image just leaves layer 4 with less to work
 * with, which is the same state the Egg has always handled (CLAUDE.md §3).
 *
 * ⚠️ IT IS ALSO PAID WORK, ONE CALL PER IMAGE. --limit is the default safety
 * rather than an option somebody remembers: a first run over a full account
 * would otherwise be a bill and a long hold on a worker pool shared with four
 * other sites. Run it again to continue; it only ever picks up what is still
 * unread.
 */
class DescribeBrandAssets extends Command
{
    protected $signature = 'assets:describe
        {--brand= : Only this brand, by slug}
        {--limit=25 : How many images to read in this run}
        {--dry-run : List what would be read, call nothing}';

    protected $description = 'Describe brand images that have no reading yet';

    public function handle(DescribeBrandAsset $describe): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = BrandAsset::query()->whereNotNull('client_id')->with('client');

        if ($slug = $this->option('brand')) {
            $client = Client::where('slug', $slug)->first();

            if ($client === null) {
                $this->error("No brand with slug \"{$slug}\".");

                return self::FAILURE;
            }

            $query->where('client_id', $client->id);
        }

        /*
         * Filtered in PHP rather than SQL on purpose. shouldRead() is where the
         * rule lives — images only, `subida` only, and unread-or-stale — and
         * duplicating it as a where-clause is exactly how the two drift apart.
         * A brand's folder is tens of rows, not millions.
         */
        $pending = $query->get()
            ->filter(fn (BrandAsset $asset) => $describe->shouldRead($asset))
            ->take($limit);

        if ($pending->isEmpty()) {
            $this->info('Nothing to read.');

            return self::SUCCESS;
        }

        $this->info("{$pending->count()} image(s) to read.");

        $read = 0;

        foreach ($pending as $asset) {
            $label = ($asset->client?->name ?? '—').' · '.$asset->title;

            if ($this->option('dry-run')) {
                $this->line("  would read: {$label}");

                continue;
            }

            if ($describe->handle($asset)) {
                $read++;
                $this->line("  read: {$label}");
            } else {
                // Never fatal: a refused mime, a missing file or a provider
                // outage all land here, and the row simply stays unread.
                $this->warn("  skipped: {$label}");
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("{$read} read.");
        }

        return self::SUCCESS;
    }
}
