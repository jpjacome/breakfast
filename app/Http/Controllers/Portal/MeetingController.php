<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\ActiveBrand;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reuniones, from the client's side. Read-only, always.
 *
 * There is no write path here at all — not hidden behind a permission check,
 * absent. Meetings are scheduled by Breakfast, and a brand's own team having
 * "Ver y editar" on Reuniones means they can read the notes, not move the
 * date. The route is still gated by section:reuniones, which is what stops
 * somebody typing the URL.
 */
class MeetingController extends Controller
{
    public function __construct(private readonly ActiveBrand $active) {}

    public function index(Request $request): View
    {
        $client = $request->user()->activeBrand();

        return view('portal.reuniones', [
            'section' => PortalSection::Reuniones,
            'upcoming' => $client->meetings()->upcoming()->get(),
            'past' => $client->meetings()->past()->get(),
        ]);
    }

    /**
     * Where a notification lands — item 11.
     *
     * Notifications carried `route('portal.reuniones')`, the LIST, while
     * `meeting_id` sat unused in the same payload. So a reminder about a
     * meeting three weeks out opened a page whose top half is a different
     * meeting, and one about a past meeting opened above a history list the
     * person then had to search.
     *
     * ⚠️ IT SWITCHES THE ACTIVE BRAND, and that is the half that makes the item
     * more than a link. A person in two brands has one active (ActiveBrand), so
     * a notification about the other brand's meeting would otherwise open a
     * page listing whichever brand they happened to be in — right URL, wrong
     * brand, no error anywhere. Same failure CLAUDE.md §5 point 2 describes.
     *
     * ⚠️ AND IT CHECKS ACCESS AGAINST THE MEETING'S OWN BRAND, which is why the
     * route does NOT carry section:reuniones. That middleware asks about the
     * ACTIVE brand — the very thing this method is about to change — so it
     * would grant or refuse based on a dropdown. Exactly the trap
     * canReachBrandAsset() exists to document. The gate is not missing; it
     * moved in here, where it can be asked the right question.
     *
     * ⚠️ IT REDIRECTS RATHER THAN RENDERING. The fragment is what scrolls the
     * page and what lights the row up (`:target` in dashboard.css) — the
     * browser doing both, before first paint, with no script and no second
     * "which one was it" passed through the view. It also leaves one canonical
     * URL for the list, so a reload or a bookmark does not repeat the
     * brand switch.
     */
    public function show(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $brand = $meeting->client;

        // Fail closed, and 404 rather than 403: somebody who was never given
        // Reuniones must not learn that this meeting exists (CLAUDE.md §6).
        abort_unless($brand !== null && $user->canRead(PortalSection::Reuniones, $brand), 404);

        // set() refuses a brand that is not theirs, so a guessed id cannot move
        // anybody into a brand they do not belong to.
        abort_unless($this->active->set($user, $brand), 404);

        return redirect()->to(route('portal.reuniones').'#reunion-'.$meeting->id);
    }
}
