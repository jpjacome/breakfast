<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves one file out of a brand's folder.
 *
 * ONE ROUTE FOR BOTH SIDES, on purpose. The link pasted into an entregable has
 * to work for the Breakfast team writing it and for the client reading it, so
 * a single URL answers both — and User::canReachBrandAsset() is the one place
 * that decides which of them may have it.
 *
 * ⚠️ This is the reason assets live under storage/app/ and not public/. A file
 * in public/ is served by Apache before PHP ever runs, so no check like this
 * one would happen, and one brand's brandbook would be a guessed URL away from
 * another's.
 *
 * 404 rather than 403, matching every other gate in the app: someone probing
 * asset ids learns nothing about which ones exist.
 */
class BrandAssetController extends Controller
{
    public function download(Request $request, BrandAsset $asset): StreamedResponse
    {
        abort_unless($request->user()?->canReachBrandAsset($asset), 404);

        $disk = Storage::disk($asset->disk);

        // A row whose file is gone is a 404 too, not a 500. It happens when a
        // deploy overwrites storage/, and an error page helps nobody.
        abort_unless($disk->exists($asset->path), 404);

        // Inline: these are logos and brandbooks people want to look at, and a
        // forced download for a PNG is a worse answer than showing it. The
        // browser still offers to save it.
        return $disk->response($asset->path, $asset->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($asset->original_name).'"',
        ]);
    }
}
