<?php

namespace App\Http\Controllers\Admin;

use App\Actions\EnforceBrandPermissionCeiling;
use App\Actions\InviteUserToClient;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientUserRequest;
use App\Http\Requests\UpdateClientUserRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
    public function store(StoreClientUserRequest $request, Client $client, InviteUserToClient $invite): RedirectResponse
    {
        $result = $invite->handle(
            client: $client,
            name: $request->validated('name'),
            email: $request->validated('email'),
            role: UserRole::from($request->validated('role')),
            permissions: $request->permissions(),
        );

        $user = $result['user'];

        // An account that already existed was ADDED to this brand, not created
        // (ACC-01). No password to read out and no mail was sent, so say what
        // actually happened rather than claiming a new account.
        if ($result['existed']) {
            return back()->with(
                'status',
                "{$user->name} ya tenía cuenta, así que la agregamos a esta marca. "
                .'Entra con su contraseña de siempre.'
            );
        }

        return back()->with([
            'status' => $result['delivered']
                ? "Cuenta creada para {$user->name}. Le enviamos un enlace para crear su contraseña."
                : "Cuenta creada para {$user->name}. No se pudo enviar el correo — comparte la contraseña temporal.",
            // Flashed, so it survives exactly one redirect and then is gone.
            'temp_password' => $result['password'],
            'temp_password_for' => $user->email,
        ]);
    }

    /**
     * Change what a brand's user can reach.
     *
     * When the target is an owner this is where a whole brand's ceiling moves,
     * so the members are brought back under it in the same request — otherwise
     * lowering an owner would leave their team holding more than that owner
     * could grant today.
     */
    public function update(
        UpdateClientUserRequest $request,
        Client $client,
        User $user,
        EnforceBrandPermissionCeiling $ceiling,
    ): RedirectResponse {
        $this->authorizeMembership($client, $user);

        $client->users()->updateExistingPivot($user->id, [
            'permissions' => json_encode((object) $request->permissions()),
        ]);

        // The brand is passed explicitly: owning is per brand now, and this
        // person may be an owner somewhere else entirely.
        $lowered = $user->isBrandOwner($client) ? $ceiling->handle($client) : 0;

        return back()->with('status', $lowered === 0
            ? "Actualizamos los permisos de {$user->name}."
            : "Actualizamos los permisos de {$user->name}. Ajustamos también a {$lowered} ".
              ($lowered === 1 ? 'persona de su equipo.' : 'personas de su equipo.'));
    }

    /** Re-send the "create your password" email. */
    public function resend(Request $request, Client $client, User $user, InviteUserToClient $invite): RedirectResponse
    {
        $this->authorizeMembership($client, $user);

        return back()->with(
            'status',
            // Named explicitly: the account may be in several brands, and the
            // mail is about being invited to THIS one.
            $invite->sendSetupLink($user, $client)
                ? "Enlace reenviado a {$user->email}."
                : 'No se pudo enviar el correo. Revisa la configuración de mail.'
        );
    }

    /**
     * Take someone off this brand.
     *
     * ⚠️ Detaches the membership and deletes the account only when that was
     * their last brand — see the same reasoning in Portal\TeamController. A
     * person removed from one brand keeps the others, and keeps the password
     * they already set.
     */
    public function destroy(Request $request, Client $client, User $user): RedirectResponse
    {
        $this->authorizeMembership($client, $user);

        // Guard against an admin deleting themselves via a crafted URL.
        abort_if($user->is($request->user()), 403);

        $name = $user->name;

        $client->users()->detach($user->id);

        if ($user->brands()->count() === 0) {
            $user->delete();
        }

        return back()->with('status', "Se quitó el acceso de {$name}.");
    }

    /**
     * The person must actually be in this brand.
     *
     * Was a client_id comparison; it is a membership question now, and asking
     * it in one place keeps the three routes from drifting. 404 rather than
     * 403, like every other missing access here (CLAUDE.md §6).
     */
    private function authorizeMembership(Client $client, User $user): void
    {
        abort_unless($user->brands()->whereKey($client->getKey())->exists(), 404);
    }
}
