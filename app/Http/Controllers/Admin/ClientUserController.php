<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientUserRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Breakfast staff create the accounts for a brand's team. There is no open
 * sign-up — see the disabled registration feature in config/fortify.php.
 *
 * The account is created with a random password nobody needs to know: the
 * user sets their own through the standard reset-password flow. The temporary
 * password is shown once so an admin can read it out if the email doesn't
 * arrive, then never again.
 */
class ClientUserController extends Controller
{
    public function store(StoreClientUserRequest $request, Client $client): RedirectResponse
    {
        $temporaryPassword = Str::password(14, symbols: false);

        $user = $client->users()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'role' => $request->validated('role'),
            'password' => Hash::make($temporaryPassword),
        ]);

        $delivered = $this->sendSetupLink($user->email);

        return back()->with([
            'status' => $delivered
                ? "Cuenta creada para {$user->name}. Le enviamos un enlace para crear su contraseña."
                : "Cuenta creada para {$user->name}. No se pudo enviar el correo — comparte la contraseña temporal.",
            // Flashed, so it survives exactly one redirect and then is gone.
            'temp_password' => $temporaryPassword,
            'temp_password_for' => $user->email,
        ]);
    }

    /** Re-send the "create your password" email. */
    public function resend(Request $request, Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);

        return back()->with(
            'status',
            $this->sendSetupLink($user->email)
                ? "Enlace reenviado a {$user->email}."
                : 'No se pudo enviar el correo. Revisa la configuración de mail.'
        );
    }

    public function destroy(Request $request, Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);

        // Guard against an admin deleting themselves via a crafted URL.
        abort_if($user->is($request->user()), 403);

        $name = $user->name;
        $user->delete();

        return back()->with('status', "Se quitó el acceso de {$name}.");
    }

    /**
     * Password-reset link doubles as the invitation. Mail failures must not
     * lose the account that was just created, so this never throws.
     */
    private function sendSetupLink(string $email): bool
    {
        try {
            return Password::broker()->sendResetLink(['email' => $email])
                === Password::RESET_LINK_SENT;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
