<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

/**
 * /admin/cuenta — your own account, as opposed to /admin/equipo, which is
 * everybody else's.
 *
 * The writes are not here. Name and password go to Fortify's own endpoints
 * (PUT /user/profile-information and PUT /user/password), which were already
 * wired up and only ever lacked a screen; two-factor goes to
 * AccountTwoFactorController. This class draws the page and nothing more.
 */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // Three states, not two. Between "activar" and typing the first code
        // there is a secret on the record that is not protecting anything yet,
        // and that half-finished setup is what the QR panel is for.
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return view('admin.account', [
            'user' => $user,
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => $pending,

            // Built here rather than in the view so the Blade file has no
            // decryption in it. Null unless there is a setup to finish.
            'qrCode' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $pending
                ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret)
                : null,

            // Recovery codes are shown once, when they are generated, and are
            // flashed by the controller that generated them. Reading them back
            // out of the record on every page load would hand the whole second
            // factor to anyone who reached a signed-in browser.
            'recoveryCodes' => session('recovery_codes'),

            'brandCount' => $user->coversEveryBrand() ? null : $user->assignedClients()->count(),
        ]);
    }
}
