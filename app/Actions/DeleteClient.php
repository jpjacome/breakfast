<?php

namespace App\Actions;

use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Archiving a brand, restoring it, and destroying it for good.
 *
 * THE ONLY CLASS THAT DELETES A BRAND. Two very different operations live here
 * on purpose, so the difference between them is one place and not four:
 *
 *   archive()  soft delete. Everything survives. The brand leaves the lists,
 *              its people lose the portal, and it comes back whole.
 *   purge()    the row and everything under it, gone. Not recoverable.
 *
 * Archiving is the one offered first everywhere, because "delete the wrong
 * brand" is a mistake somebody makes at 7pm and notices at 9am.
 */
class DeleteClient
{
    /**
     * Archive: the brand goes away and can come back.
     *
     * Its users stop being able to use the portal the moment this runs — not
     * by any change to their rows, but because User::accessTo() fails closed
     * when the brand behind a client user is gone. Nothing to remember to undo
     * on restore, which is exactly why it is done that way.
     */
    public function archive(Client $client): void
    {
        $client->delete();
    }

    public function restore(Client $client): void
    {
        $client->restore();
    }

    /**
     * Destroy the brand and everything under it.
     *
     * ⚠️ FILES ARE DELETED THROUGH ELOQUENT FIRST, deliberately. Every child
     * table has an ON DELETE CASCADE, which the database applies without ever
     * loading a model — so BrandAsset::booted()'s deleted() hook would never
     * fire and every uploaded file would be left orphaned on disk forever.
     * Deleting them one by one is slower and is the only way the bytes go.
     *
     * The users are deleted too. A client user is a person's access to ONE
     * brand; with the brand gone the account is a login that can reach nothing,
     * and leaving it would let somebody sign in to an empty portal.
     */
    public function purge(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $client->brandAssets()->each(fn (BrandAsset $asset) => $asset->delete());

            $client->users()->delete();

            // Everything else — entregables, pasos, reuniones, el hilo del
            // asistente — goes with the row on the database's own cascade.
            $client->forceDelete();
        });
    }
}
