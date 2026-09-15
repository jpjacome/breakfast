<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The brand's files, from the client's side. Read-only, always.
 *
 * No write path at all — not hidden behind a permission check, absent. Files
 * are uploaded by Breakfast; "Ver y editar" on Brand assets means the brand's
 * own team can hand the files around, not replace them.
 *
 * Everything in the folder is here, whether or not it belongs to an entregable
 * — everything the brand is meant to have, that is. A brand's folder also holds
 * Breakfast's own working files: the contract, the pricing sheet, the notes.
 * Those are filtered out by sharedWithClient(), and they are not listed as
 * hidden either, because naming a file somebody cannot open still tells them it
 * exists. The download route refuses them separately; see
 * User::canReachBrandAsset().
 */
class BrandAssetController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.brand-assets', [
            'section' => PortalSection::BrandAssets,
            'assets' => $request->user()->activeBrand()->brandAssets()->sharedWithClient()->get(),
        ]);
    }
}
