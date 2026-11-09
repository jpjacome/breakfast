<?php

namespace App\Http\Controllers\Portal;

use App\Actions\InviteUserToClient;
use App\Enums\BrandRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /portal/equipo — the brand owner's own team.
 *
 * Reachable only through the 'section:equipo' middleware, which is owner-only
 * by construction (PortalSection::Equipo is not grantable). Every method still
 * checks the brand on the record it was handed: the middleware proves you own
 * *a* brand, not that this user belongs to yours.
 */
class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $client = $request->user()->activeBrand();

        return view('portal.equipo', [
            'client' => $client,
            // ⚠️ users.id, qualified. The relation is a join now, and both
            // tables have an id — an unqualified one is an ambiguous-column
            // error rather than a wrong answer, but only at runtime.
            'members' => $client->users()
                ->where('users.id', '!=', $request->user()->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreTeamMemberRequest $request, InviteUserToClient $invite): RedirectResponse
    {
        $email = $request->validated('email');

        $result = $invite->handle(
            client: $request->user()->activeBrand(),
            name: $request->validated('name'),
            email: $email,
            role: UserRole::ClienteMiembro,
            permissions: $request->permissions(),
            // ⚠️ THE OWNER ASKS, IT DOES NOT ADD - queued item B. An address
            // that already has an account produces an invitation that person
            // answers, and nothing reaches brand_user until they do.
            withConsent: true,
            invitedBy: $request->user(),
        );

        /*
         * ⚠️ ONE SENTENCE FOR BOTH OUTCOMES, AND IT NAMES THE ADDRESS RATHER
         * THAN THE PERSON.
         *
         * An owner must not learn whether an address already has an account.
         * Saying "invitamos a Ana" when the account existed and "creamos la
         * cuenta de Ana" when it did not tells them exactly that, and so does a
         * name appearing that they never typed. The address is the one thing
         * they already know, because they just typed it.
         */
        return back()->with([
            'status' => "Enviamos una invitación a {$email}.",
            // Flashed, so it survives exactly one redirect and then is gone.
            // Null on the consent path, which has no account to read out.
            'temp_password' => $result['password'],
            'temp_password_for' => $email,
        ]);
    }

    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        $client = $this->authorizeMember($request, $user);

        $client->users()->updateExistingPivot($user->id, [
            'permissions' => json_encode((object) $request->permissions()),
        ]);

        return back()->with('status', "Actualizamos los permisos de {$user->name}.");
    }

    /**
     * Take someone off this brand.
     *
     * ⚠️ DETACHES THE MEMBERSHIP AND NOTHING ELSE. With one account across
     * several brands (ACC-01), deleting the row would take away access to
     * brands this owner has nothing to do with — an owner of one brand must
     * never be able to close somebody out of another.
     *
     * ⚠️ IT USED TO DELETE THE ACCOUNT when that was their last brand, and
     * that is gone (2026-09-21) — see the long note in
     * Admin\ClientUserController::destroy() for the whole reasoning. The short
     * version: `User` has no SoftDeletes, so it was permanent and cascaded
     * their entire history, and an owner clicking "quitar" cannot be asked to
     * understand that the button means two different things depending on a
     * count they cannot see.
     *
     * ⚠️ THE MESSAGE STAYS GENERIC, unlike the admin's. Telling an owner "ya no
     * entra a ninguna marca" would tell them this person is in no OTHER brand,
     * which is not their business — the same cross-brand fact that queued
     * item B keeps out of the invite form.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $client = $this->authorizeMember($request, $user);

        $name = $user->name;

        $client->users()->detach($user->id);

        return back()->with('status', "Quitamos el acceso de {$name}.");
    }

    /**
     * The target must be a member of the brand the signed-in owner is in.
     *
     * Without the role check an owner could edit or delete a co-owner by
     * posting their id; without the brand check they could reach into another
     * brand entirely. Both questions are asked of the MEMBERSHIP now — being a
     * member is per brand, so "is this person a mere member" has no answer
     * until you say where.
     */
    private function authorizeMember(Request $request, User $user): Client
    {
        $client = $request->user()->activeBrand();

        abort_if($client === null, 404);

        $role = $user->brandRole($client);

        // Not in this brand at all: 404, because a stranger's existence is not
        // this owner's business (CLAUDE.md §6). In the brand but a co-owner:
        // 403, which is the same answer as before and says "not this person".
        abort_if($role === null, 404);
        abort_unless($role === BrandRole::Miembro, 403);
        abort_if($user->is($request->user()), 403);

        return $client;
    }
}
