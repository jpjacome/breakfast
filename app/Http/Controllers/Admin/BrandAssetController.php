<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AssetVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandAssetRequest;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

/**
 * Filling a brand's folder. Only Breakfast uploads; the client only reads.
 *
 * A shared file lands in the client's files screen whether or not it belongs to
 * an entregable — the folder is the brand's folder, not a by-product of the
 * board. An entregable that IS an asset holds the file's link as its text, and
 * the copy-link button on this screen is how that link gets there.
 *
 * ⚠️ AND SOME FILES ARE BREAKFAST'S ALONE: the contract, the pricing sheet, the
 * team's working notes. Those live in the same folder with visibility=interno
 * and never reach the brand — see AssetVisibility, and User::canReachBrandAsset()
 * which is what actually refuses the download.
 */
class BrandAssetController extends Controller
{
    public function store(StoreBrandAssetRequest $request, Client $client): RedirectResponse
    {
        /** @var array<int, UploadedFile> $files */
        $files = $request->file('files');
        $single = count($files) === 1;
        $folder = BrandAsset::folderFor($client);
        $visibility = $request->visibility();

        foreach ($files as $file) {
            // store() names the file itself, so two uploads called logo.png do
            // not overwrite each other. The original name is kept on the row
            // for display and for the download's filename.
            $path = $file->store($folder, 'local');

            $client->brandAssets()->create([
                'uploaded_by' => $request->user()->id,
                'title' => $request->titleFor($file->getClientOriginalName(), $single),
                // One choice for the whole upload: these arrive as a batch —
                // a logo pack, a set of notes — and they are the same kind of
                // thing as each other far more often than not.
                'visibility' => $visibility,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }

        $count = count($files);

        // The message says who can see it, because that is the half of this
        // action somebody might have got wrong and cannot see from the row
        // they just created.
        $what = $count === 1 ? 'Archivo subido' : "{$count} archivos subidos";

        $who = $visibility->isInternal()
            ? 'Sólo los ve el equipo de Breakfast.'
            : 'Ya los ve la marca en sus archivos.';

        // back(), not a named route: this form is on the process screen and on
        // the file manager, and an upload that answers by moving you to the
        // other one is a bug you have to undo by hand.
        return back()->with('status', "{$what}. {$who}");
    }

    /**
     * Change who may see a file that is already uploaded.
     *
     * ⚠️ THE POINT IS THE MISTAKE, not the tidiness. Picking the wrong option
     * on the upload form puts a contract in front of a client, and without this
     * the only fix would be deleting the file — which also breaks any entregable
     * linking it. One click, no data lost, and the link keeps working for
     * Breakfast either way.
     */
    public function visibility(Client $client, BrandAsset $asset): RedirectResponse
    {
        $moved = $asset->isInternal()
            ? AssetVisibility::Compartido
            : AssetVisibility::Interno;

        $asset->update(['visibility' => $moved]);

        return back()->with('status', $moved->isInternal()
            ? "«{$asset->title}» ya no lo ve la marca."
            : "«{$asset->title}» ya lo ve la marca en sus archivos.");
    }

    /**
     * Delete a file.
     *
     * ⚠️ The row going means the file goes too — see BrandAsset::booted(). Any
     * entregable holding this asset's link keeps the link, which will then
     * 404. That is deliberate: silently editing 48 text columns to remove a
     * URL would be a worse surprise than a dead link somebody can see and fix.
     */
    public function destroy(Client $client, BrandAsset $asset): RedirectResponse
    {
        $title = $asset->title;

        $asset->delete();

        return back()
            ->with('status', "«{$title}» eliminado. Si algún entregable lo enlazaba, ese link ya no funciona.");
    }
}
