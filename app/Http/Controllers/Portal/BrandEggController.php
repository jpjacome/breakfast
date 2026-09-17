<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\BrandEggLayer;
use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The brand's own Brand Egg — read-only, and always reachable.
 *
 * ⚠️ IT USED TO 404 UNTIL THE EGG WAS APPROVED, AND THAT CHANGED 2026-09-17.
 * Hiding the page entirely meant a brand had no idea the Brand Egg was part of
 * what they were getting until the day it appeared, and the section it lives
 * under looked like it was missing something nobody had mentioned. Breakfast
 * asked for it to be visible from the start.
 *
 * ⚠️ WHAT APPROVAL STILL GATES IS THE WORDS, and that has not moved an inch.
 * The drawing and the five layer PURPOSES are the same on every brand's egg —
 * they describe the shape of the thing, not this brand — so showing them says
 * nothing about anybody. The composed TEXT is what a person signed off, and an
 * unapproved layer shows none of it. The Egg is the single artefact in this app
 * whose whole claim is that somebody checked it; a draft shown to the brand
 * would undo that claim.
 *
 * ⚠️ AND THE EMPTY STATE IS NOT PHRASED AS BREAKFAST'S UNFINISHED HOMEWORK.
 * ERR-07 of the beta review: "todavía no está aprobado" is a sentence about
 * internal work said to the wrong audience. What a client is told is what the
 * thing IS and that it is being built with them.
 */
class BrandEggController extends Controller
{
    public function show(Request $request): View
    {
        // ⚠️ THE ACTIVE BRAND, never $user->client. A client user has many
        // brands and which one this request is about is a choice held in the
        // session (CLAUDE.md §5, trap 16).
        $client = $request->user()->activeBrand();

        return view('portal.brand-egg', [
            'section' => PortalSection::Estrategia,
            'client' => $client,
            'egg' => $client->brandEggOrNew(),
            'layers' => BrandEggLayer::cases(),
            // ⚠️ THE ONE THING APPROVAL DECIDES HERE: whether the composed text
            // of each layer is shown. The shape is always shown.
            'approved' => $client->brandEggState()->isVisibleToClient(),
        ]);
    }
}
