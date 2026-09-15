<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Turning two-step verification on and off, from /admin/cuenta.
 *
 * Fortify ships routes for all four of these already, so why not use them:
 * they sit behind the 'password.confirm' middleware, and that middleware
 * cannot resume a POST. It sends you to the confirm-password screen and then
 * returns you to the *previous* page, so the click you made is thrown away and
 * you have to make it again — which reads as the button being broken.
 *
 * So the password check is a field in the form instead of a screen in front of
 * it. Same control, one step: you cannot arm, disarm, or reissue the recovery
 * codes of an account with only a borrowed session.
 */
class AccountTwoFactorController extends Controller
{
    public function store(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $this->confirmPassword($request, 'enableTwoFactor');

        $enable($request->user());

        // Not "activada" — there is nothing protecting the account until the
        // first code comes back. See confirm() below.
        return back()->with('status', 'Escanea el código con tu app y escribe los seis dígitos.');
    }

    /**
     * The code from the authenticator app, which is the only proof that the
     * secret actually reached a device. Until this lands, a mistyped setup
     * would lock the account on the next sign-in.
     */
    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        // Fortify's action throws into the 'confirmTwoFactorAuthentication'
        // bag, so the wrong-code message lands beside this form on its own.
        $confirm($request->user(), (string) $request->input('code'));

        return back()
            ->with('status', 'Verificación en dos pasos activada.')
            ->with('recovery_codes', $request->user()->recoveryCodes());
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        // A setup that was never confirmed is not guarding anything, so
        // abandoning it halfway asks for no password. Switching off a live
        // second factor does.
        if ($request->user()->two_factor_confirmed_at !== null) {
            $this->confirmPassword($request, 'disableTwoFactor');

            $message = 'Verificación en dos pasos desactivada.';
        } else {
            $message = 'Configuración cancelada.';
        }

        $disable($request->user());

        return back()->with('status', $message);
    }

    /**
     * Fresh recovery codes. Also the only way back to them once the one-time
     * reveal is gone — reissuing is cheap, and the old set stops working.
     */
    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        $this->confirmPassword($request, 'regenerateRecoveryCodes');

        $generate($request->user());

        return back()
            ->with('status', 'Códigos de recuperación nuevos. Los anteriores ya no sirven.')
            ->with('recovery_codes', $request->user()->fresh()->recoveryCodes());
    }

    /**
     * @param  string  $bag  Errors go to the form that was submitted rather than
     *                       to the page, which carries three forms.
     */
    private function confirmPassword(Request $request, string $bag): void
    {
        Validator::make($request->all(), [
            'current_password' => ['required', 'string', 'current_password:web'],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para confirmar.',
            'current_password.current_password' => 'Esa no es tu contraseña actual.',
        ])->validateWithBag($bag);
    }
}
