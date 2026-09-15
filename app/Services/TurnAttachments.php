<?php

namespace App\Services;

use App\Models\BrandAsset;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a chat turn carried, as something a screen can draw.
 *
 * THE ONE PLACE THAT DECIDES IT. Three transcripts show attachments — Brandy on
 * /portal, the dashboard assistant, and the onboarding assistant on the process
 * board — over two different tables. Each deciding for itself what an
 * attachment looks like is three chances for the same file to appear three
 * ways, and the file-type reading is fiddly enough to drift (see
 * BrandAsset::icon(), which reads the extension rather than the mime because
 * design tools lie about mimes).
 *
 * ⚠️ IT ANSWERS FROM THE ASSET, AND FALLS BACK TO THE NAME. Every turn from
 * before the files were kept has names and no asset, because the bytes were
 * dropped on the way to the provider. Those are not errors and must not render
 * as broken images: a name is all that is known about them, so a name is all
 * they show.
 *
 * ⚠️ IT NEVER DECIDES ACCESS. canReachBrandAsset() does, on every request for
 * the file itself — a thumbnail goes through the same gated route as the link
 * beside it, so showing one is no wider a door. What this does is avoid
 * PRINTING a row the viewer could not open, which is the same split as
 * scopeSharedWithClient() versus the gate: one stops the telling, the other
 * stops the download.
 */
class TurnAttachments
{
    /**
     * @param  array<int, string>|null  $names  what the turn recorded
     * @param  array<int, int>|null  $ids  which assets they became, if any
     * @return Collection<int, array{name: string, asset: ?BrandAsset, kind: string}>
     */
    public function for(?array $names, ?array $ids, ?User $viewer = null): Collection
    {
        $names = array_values(array_filter((array) $names, 'is_string'));

        if ($names === []) {
            return collect();
        }

        $assets = $this->assets($ids, $viewer);

        return collect($names)->values()->map(function (string $name, int $index) use ($assets): array {
            // Positional, because that is how the two arrays were written: the
            // Nth name is the Nth file. A turn whose keep failed halfway has
            // fewer ids than names, and the tail simply has no asset.
            $asset = $assets[$index] ?? null;

            return [
                'name' => $name,
                'asset' => $asset,
                'kind' => $this->kind($asset),
            ];
        });
    }

    /**
     * The assets this turn's ids point at, in the turn's own order.
     *
     * Missing ones are left as null rather than dropped: a file deleted from
     * the brand's folder should leave its name in the conversation, not shift
     * every attachment after it onto the wrong name.
     *
     * @param  array<int, int>|null  $ids
     * @return array<int, ?BrandAsset>
     */
    private function assets(?array $ids, ?User $viewer): array
    {
        $ids = array_values(array_filter((array) $ids, 'is_numeric'));

        if ($ids === []) {
            return [];
        }

        $found = BrandAsset::query()->whereKey($ids)->get()->keyBy('id');

        return array_map(function ($id) use ($found, $viewer): ?BrandAsset {
            $asset = $found->get((int) $id);

            if ($asset === null) {
                return null;
            }

            // Fails closed. A viewer who could not open the file is not told it
            // exists — they get the bare name, exactly like a turn from before
            // the files were kept.
            return $viewer === null || $viewer->canReachBrandAsset($asset) ? $asset : null;
        }, $ids);
    }

    /**
     * How this attachment should be drawn.
     *
     * ⚠️ `video` here means a video a BROWSER CAN PLAY, not any video mime.
     * BrandAsset::isPlayableVideo() is deliberate about that: a .mov uploads as
     * video/* and plays in nothing, and a preview box showing a black
     * rectangle is worse than the filename it replaced.
     */
    private function kind(?BrandAsset $asset): string
    {
        return match (true) {
            $asset === null => 'name',
            $asset->isImage() => 'image',
            $asset->isPlayableVideo() => 'video',
            default => 'file',
        };
    }
}
