<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BrandEggLayer;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Contracts\View\View;

/**
 * The Brand Egg — the admin side of it.
 *
 * Five synthesised layers sitting ABOVE the 48 entregables in the assistant's
 * context. See docs/brand-egg.md: the Egg is generated from the ENTREGABLES,
 * never straight from the toolkit, because going straight from the PDF would
 * skip the one review a person actually performs and put an unreviewed reading
 * at the top of the brand's memory.
 *
 * ⚠️ THIS IS A BENCH, FOR NOW. It renders the real drawing through the real
 * component against a hand-written Egg — no table, no composer, no AI. Brand
 * Egg build order step 4 of docs/brand-egg.md §11 is pure front end, and this
 * controller exists only so there is a URL to open while the back end is built
 * behind it. When the real screen arrives at step 5 it replaces this one; the
 * route name and the {client} segment do not move.
 *
 * ⚠️ IT IS STILL A BRAND-SCOPED ROUTE. It carries {client}, so the
 * 'covers-client' middleware on the /admin group guards it the day it is
 * written, with nothing to remember. That is the whole reason brand-scoped
 * routes belong in that group rather than somewhere of their own.
 */
class ClientBrandEggController extends Controller
{
    public function edit(Client $client): View
    {
        return view('admin.clients.brand-egg', [
            'client' => $client,
            'layers' => BrandEggLayer::cases(),
        ]);
    }
}
