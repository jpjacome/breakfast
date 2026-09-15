<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /portal/perfil — your own account, on the client side.
 *
 * THE ONLY PAGE IN THE PORTAL A CLIENT CAN WRITE TO, and the reason
 * PortalSection::Perfil is always-on with Write while every other section caps
 * at Read: your own name and your own password are yours, and nobody at
 * Breakfast should be in the business of changing them for you.
 *
 * It writes nothing itself. Both forms post to Fortify's own endpoints —
 * user-profile-information.update and user-password.update — which is why
 * there is no store() or update() here and no FormRequest beside it. Fortify
 * validates into a named error bag per form and redirects back, so this
 * controller only has to hand the page what it needs to draw.
 *
 * Counterpart of Admin\AccountController, which does the same job for
 * Breakfast staff and additionally owns two-step verification. That is not
 * here yet: enabling it means portal routes onto AccountTwoFactorController
 * and a screen for the QR, and this page exists first because a client who
 * cannot change their password has no way to react to a leaked one.
 */
class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('portal.perfil', [
            'section' => PortalSection::Perfil,
            'user' => $user,
            'client' => $user->activeBrand(),

            // What they can reach, read off the same helper the sidebar and
            // the middleware use. Printed because "why can I not see
            // Reuniones?" is the question this page gets asked, and the honest
            // answer is a list plus who to ask — not a support mail to us.
            'sections' => array_values(array_filter(
                PortalSection::grantable(),
                fn (PortalSection $section) => $user->canRead($section),
            )),
        ]);
    }
}
