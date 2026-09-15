<?php

namespace App\Actions;

use App\Enums\BrandRole;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Create an account on a brand and send its owner the link to set a password.
 *
 * Used from two places that must behave identically: Breakfast staff adding
 * someone in /admin, and a brand owner adding a teammate in /portal/equipo.
 *
 * The account is created with a random password nobody needs to know — the
 * user sets their own through the standard reset flow. The temporary one is
 * returned so the caller can show it once if the mail does not arrive.
 */
class InviteUserToClient
{
    /**
     * @param  array<string, string>  $permissions  PortalSection => AccessLevel.
     * @return array{user: User, password: ?string, delivered: bool, existed: bool}
     */
    public function handle(
        Client $client,
        string $name,
        string $email,
        UserRole $role,
        array $permissions = [],
    ): array {
        // ⚠️ AN ACCOUNT THAT ALREADY EXISTS IS ADDED, NOT DUPLICATED — this is
        // the whole point of ACC-01. Before it, users.email being unique meant
        // a person working with two brands needed two addresses.
        //
        // No setup mail and no temporary password: they already have a
        // password, and sending "creá tu contraseña" to somebody who has been
        // signing in for months reads as a break-in attempt. What changed for
        // them is a brand appearing in their picker.
        //
        // Only Breakfast reaches this branch. A brand owner inviting a known
        // address is refused in StoreTeamMemberRequest, because attaching would
        // tell them an account exists on that address — see docs/multimarca.md.
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $this->attach($client, $existing, $role, $permissions);

            return [
                'user' => $existing,
                'password' => null,
                'delivered' => false,
                'existed' => true,
            ];
        }

        $temporaryPassword = Str::password(14, symbols: false);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => Hash::make($temporaryPassword),
            // ⚠️ Kept so the account still records the brand it was created
            // for, and so the invitation mail can name one. It is NOT what the
            // portal reads — brand_user is (ACC-01). See docs/multimarca.md §2.
            'client_id' => $client->id,
        ]);

        $this->attach($client, $user, $role, $permissions);

        return [
            'user' => $user,
            'password' => $temporaryPassword,
            'delivered' => $this->sendSetupLink($user, $client),
            'existed' => false,
        ];
    }

    /**
     * Put an existing person into a brand — ACC-01.
     *
     * The membership, not the account: their role HERE and what they may open
     * HERE. Separate from handle() because with one account across brands the
     * two stopped being the same act — adding somebody to a second brand must
     * not mint a second account, and must not touch the password they already
     * have.
     *
     * ⚠️ Idempotent on purpose. Re-adding somebody already in the brand
     * rewrites their membership rather than failing on the unique index, which
     * is what the person clicking "añadir" means when the row is already there.
     *
     * @param  array<string, string>  $permissions  PortalSection => AccessLevel.
     */
    public function attach(
        Client $client,
        User $user,
        BrandRole|UserRole $role,
        array $permissions = [],
    ): void {
        $user->brands()->syncWithoutDetaching([
            $client->id => [
                'role' => $this->brandRole($role)->value,
                // Owners carry a map too. What owning adds is Equipo and
                // Suscripción, which User::accessTo() grants by role rather
                // than storing here — see its docblock.
                'permissions' => json_encode((object) $permissions),
            ],
        ]);

        $user->unsetRelation('brands');
    }

    /**
     * Accepts either vocabulary while the old one is still being retired.
     * UserRole said which side of the app you are on AND what you are in your
     * brand; only the second half belongs on a membership.
     */
    private function brandRole(BrandRole|UserRole $role): BrandRole
    {
        if ($role instanceof BrandRole) {
            return $role;
        }

        return $role === UserRole::ClienteOwner ? BrandRole::Owner : BrandRole::Miembro;
    }

    /**
     * A password-reset token is the mechanism behind the invitation, but the
     * mail says what actually happened — see ClientInvitation. The token is
     * minted directly rather than through sendResetLink() so the wording is
     * ours and so re-inviting is not silently swallowed by the broker's
     * one-per-minute throttle.
     *
     * A mail failure must not lose the account that was just created, so this
     * never throws; the caller falls back to reading out the temporary
     * password instead.
     */
    public function sendSetupLink(User $user, ?Client $client = null): bool
    {
        // The brand is passed in rather than read off the user: an account can
        // now belong to several, so "their brand" stopped being a question the
        // user row can answer. Falls back to the brand the account was created
        // for, which is what the resend path on /admin means.
        $client ??= $user->client;

        if ($client === null) {
            return false;
        }

        try {
            $user->notify(new ClientInvitation(
                token: Password::broker()->createToken($user),
                client: $client,
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
