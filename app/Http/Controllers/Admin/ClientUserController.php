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
     *
     * ⚠️ DETACHING IS NOT DELETING, AND CONFLATING THEM MADE THIS ROUTE REFUSE
     * THE ONE CASE IT WAS NEEDED FOR. The guard here used to be a flat 403 on
     * yourself, written against account deletion — correct about that, and
     * wrong about the row. Staff could be attached to a brand
     * (StoreClientUserRequest lets nothing do that now), and an admin who
     * attached their own address then could not take it off again: every
     * attempt was their own id, so every attempt was the 403. The membership
     * was unremovable through the app that created it.
     *
     * ⚠️ AND NOTHING HERE DELETES AN ACCOUNT ANY MORE (2026-09-21). This route
     * used to end with `if ($user->brands()->count() === 0) { $user->delete(); }`,
     * so taking somebody off their ONLY brand destroyed the account — and
     * `User` has no SoftDeletes, so it was permanent, and the foreign keys took
     * their whole Brandy history with it: `assistant_messages`,
     * `conversations`, `conversation_folders`, `user_files` and `passkeys` all
     * cascade. Attribution elsewhere went to NULL (`checklist_ticks.checked_by`,
     * `brand_assets.uploaded_by`, `meetings.created_by` and the rest).
     *
     * Two reasons that had to go:
     *
     *   · the button could not say which it would do. Same dialog, same
     *     wording, and whether it detached a row or erased a person depended
     *     on a count nobody looking at the screen could see — and one brand is
     *     the common case, not the edge case.
     *   · it was irreversible in an app where the neighbouring destructive act
     *     is not. Archiving a BRAND is a soft delete and restores everybody's
     *     access exactly; removing the last person from one was unrecoverable.
     *
     * So this route detaches, full stop. A client user in no brand is a broken
     * record on purpose — `accessTo()` fails closed on it — so they are locked
     * out rather than erased, and `/portal` still answers them 200 the way it
     * answers Breakfast staff, who carry no brands either. Re-adding them is
     * the ordinary add-user form: an address that already has an account is
     * attached, not duplicated (ACC-01).
     *
     * Deleting an account for good is deliberately not a side effect of a
     * membership edit. If it is ever wanted it needs its own route, its own
     * wording and its own confirmation.
     */
    public function destroy(Request $request, Client $client, User $user): RedirectResponse
    {
        $this->authorizeMembership($client, $user);

        $name = $user->name;

        $client->users()->detach($user->id);

        // Asked AFTER the detach: "does this leave them with nothing" is a
        // question about the state we just created.
        $stranded = ! $user->isBreakfast() && $user->brands()->count() === 0;

        return back()->with('status', match (true) {
            // Nothing changed about what staff can reach — they reach every
            // brand by role — so "se quitó el acceso" would claim something
            // this row never granted.
            $user->isBreakfast() => "Quitamos a {$name} de la lista de esta marca. Sigue entrando como equipo de Breakfast.",
            // Say that the account survived and that it now reaches nothing,
            // because otherwise the only way to find out is for the person to
            // try to sign in.
            $stranded => "Se quitó el acceso de {$name}. La cuenta sigue existiendo, pero ya no entra a ninguna marca.",
            default => "Se quitó el acceso de {$name}.",
        });
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
