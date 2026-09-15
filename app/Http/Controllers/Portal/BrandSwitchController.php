<?php

namespace App\Http\Controllers\Portal;

use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActiveBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Change which of your brands the portal is showing — ACC-02.
 *
 * No section gate, and that is deliberate: switching is not access to anything.
 * What the person may then OPEN is decided per brand by EnsurePortalSectionAccess
 * exactly as before, so arriving in a brand where they hold nothing simply
 * leaves them with a sidebar of one item.
 *
 * ⚠️ ActiveBrand::set() refuses a brand that is not theirs, so the {client} in
 * the URL is not a way into somebody else's portal. It is checked there rather
 * than here because that is the class that knows what "theirs" means — the
 * same reason nothing outside User reads the permissions map.
 */
class BrandSwitchController extends Controller
{
    public function __invoke(Request $request, Client $client, ActiveBrand $brands): RedirectResponse
    {
        // 404 rather than 403, like every other reach for something that is not
        // yours in this app (CLAUDE.md §6): a brand you are not in should not
        // confirm that it exists.
        abort_unless($brands->set($request->user(), $client), 404);

        // back(), not a fixed page: the picker sits in the sidebar, so it can
        // be used from anywhere. Landing on the dashboard every time would
        // throw away the screen the person was reading.
        //
        // ⚠️ Except when the screen they were on is one they cannot open here.
        // Permissions are per brand, so the page behind them may now be a 404 —
        // and a switch that lands on "no existe" reads as the switch having
        // failed. The dashboard is always reachable, so fall back to it.
        return $this->canReturn($request)
            ? back()
            : redirect()->route('portal.home');
    }

    /**
     * Is the page they came from still open to them in the new brand?
     *
     * ⚠️ No second table of which page needs which permission. A section's
     * case VALUE is its URL segment and its permissions key (see
     * App\Enums\PortalSection), so the segment answers the question directly
     * and there is nothing here that can drift from the middleware.
     */
    private function canReturn(Request $request): bool
    {
        $previous = $request->headers->get('referer');

        if ($previous === null || ! str_starts_with($previous, url('/portal'))) {
            return false;
        }

        $segment = explode('/', trim((string) parse_url($previous, PHP_URL_PATH), '/'))[1] ?? null;

        $section = $segment === null ? null : PortalSection::tryFrom($segment);

        // A portal URL that is not a section — the dashboard itself — is open
        // to anyone who got this far, so going back to it is safe.
        return $section === null || $request->user()->canRead($section);
    }
}
