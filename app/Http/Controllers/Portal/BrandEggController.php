<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\BrandEggLayer;
use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The brand's own Brand Egg — read-only, and only once it is approved.
 *
 * ⚠️ APPROVAL IS THE ONE PLACE THIS APP GATES ON IT. Everywhere else the state
 * changes what something SAYS: the assistant reads an unapproved Egg quite
 * happily and is simply told it is a draft (docs/brand-egg.md §8). Here it
 * decides whether the page exists at all, because the Egg is the single
 * artefact in this app whose whole claim is that a person signed it off —
 * showing a brand a draft of its own essence would undo the claim, and a
 * synthesis nobody has checked is exactly the unreviewed reading this app
 * exists to keep away from a client.
 *
 * ⚠️ AND IT 404s RATHER THAN EXPLAINING ITSELF. A member should not learn there
 * is a draft of their brand's essence they are not being shown — "todavía no
 * está aprobado" is a sentence about Breakfast's internal work, said to the
 * wrong audience. Same reasoning as the section middleware 404ing rather than
 * 403ing (CLAUDE.md §6).
 */
class BrandEggController extends Controller
{
    public function show(Request $request): View
    {
        // ⚠️ THE ACTIVE BRAND, never $user->client. A client user has many
        // brands and which one this request is about is a choice held in the
        // session (CLAUDE.md §5, trap 16).
        $client = $request->user()->activeBrand();

        $state = $client->brandEggState();

        if (! $state->isVisibleToClient()) {
            throw new NotFoundHttpException;
        }

        return view('portal.brand-egg', [
            'section' => PortalSection::Estrategia,
            'client' => $client,
            'egg' => $client->brandEggOrNew(),
            'layers' => BrandEggLayer::cases(),
        ]);
    }
}
