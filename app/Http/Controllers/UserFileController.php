<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving a file somebody pasted at an assistant.
 *
 * ⚠️ A SEPARATE ROUTE FROM `archivos/{asset}`, and deliberately so. That one
 * asks `canReachBrandAsset()` — a brand's visibility rules, `interno` versus
 * `compartido`, staff coverage. This one asks `UserFile::isReachableBy()`,
 * which is ownership plus Breakfast and nothing else. One method holding two
 * access models is how a gate ends up confidently answering the wrong question.
 *
 * Outside both route groups, like its sibling: it belongs to neither /admin nor
 * /portal, because both sides paste files.
 */
class UserFileController extends Controller
{
    public function download(Request $request, UserFile $file): StreamedResponse
    {
        // Fails closed, and 404 rather than 403: somebody who may not open it
        // does not learn that it exists (CLAUDE.md §6).
        abort_unless($file->isReachableBy($request->user()), 404);

        $disk = Storage::disk($file->disk);

        // A row whose file is gone is a 404 too, not a 500. It happens when a
        // deploy overwrites storage/, and an error page helps nobody.
        abort_unless($disk->exists($file->path), 404);

        // Inline: these are screenshots and references people want to look at,
        // and a forced download for a PNG is a worse answer than showing it.
        // The browser still offers to save it.
        return $disk->response($file->path, $file->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
        ]);
    }
}
