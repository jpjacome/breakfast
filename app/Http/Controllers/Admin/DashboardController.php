<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Services\Ai\OpenRouterAccount;
use App\Services\Ai\UsageStatistics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** The window every usage figure on this screen is measured over. */
    private const USAGE_DAYS = 30;

    public function __invoke(Request $request, UsageStatistics $usage, OpenRouterAccount $account): View
    {
        $user = $request->user();

        // Every brand-shaped figure on this screen is scoped: an Equipo member
        // put on two brands should see two, not a count of the whole roster.
        // The spend totals are not — those are Breakfast's own operating cost
        // and name nobody, unlike the per-brand list underneath them.
        $visible = fn () => Client::query()->visibleTo($user);

        return view('admin.dashboard', [
            'clientCount' => $visible()->count(),
            'activeCount' => $visible()->where('status', ClientStatus::Activo)->count(),

            // AI spend. usageDays is passed rather than hardcoded in the blade
            // so the label and the query can never disagree about the window.
            'usageDays' => self::USAGE_DAYS,
            'usage' => $usage->summary(self::USAGE_DAYS),
            'usageToday' => $usage->summary(1),
            'usageByModel' => $usage->byModel(self::USAGE_DAYS),
            'usageByClient' => $usage->byClient(self::USAGE_DAYS, user: $user),

            // Null unless a management key is configured — see OpenRouterAccount.
            'credits' => $account->credits(),

            // The assistant has to be pointed at a brand before it can answer
            // anything, so the composer needs the list to choose from.
            'brands' => $visible()->orderBy('name')->get(['id', 'slug', 'name']),
            'recentClients' => $visible()->withCount('brandAssets')
                ->latest()
                ->take(5)
                ->get(),
            // The files Breakfast has handed over most recently, across every
            // brand this user covers.
            'recentAssets' => BrandAsset::query()
                ->whereHas('client', fn ($client) => $client->visibleTo($user))
                ->with('client')
                ->latest()
                ->take(6)
                ->get(),
        ]);
    }
}
