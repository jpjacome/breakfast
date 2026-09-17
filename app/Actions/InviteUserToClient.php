<?php

namespace App\Actions;

use App\Enums\BrandRole;
use App\Enums\UserRole;
use App\Models\BrandInvitation;
use App\Models\Client;
use App\Models\User;
use App\Notifications\BrandMembershipInvitation;
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
     * @param  bool  $withConsent  see the branch below — true when the inviter
     *                             is a brand owner rather than Breakfast staff.
     * @return array{user: ?User, password: ?string, delivered: bool, existed: bool, pending: bool}
     */
    public function handle(
        Client $client,
        string $name,
        string $email,
        UserRole $role,
        array $permissions = [],
        bool $withConsent = false,
        ?User $invitedBy = null,
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
            /*
             * ⚠️ A BRAND OWNER MAY NOT ATTACH SOMEBODY — queued item B.
             *
             * Attaching on the spot would tell the owner that an address they
             * only guessed at has an account, which is somebody else's
             * business. So does refusing with "ya existe una cuenta", which is
             * what this app did until 2026-09-17 while its own comment claimed
             * to prevent exactly that. Both answers leak, in opposite
             * directions; the only non-answer is to do the same visible thing
             * either way and ask the invited person.
             *
             * Breakfast staff still attach directly. They administer every
             * brand and every account, so there is nothing to keep from them —
             * and somebody has to be able to put a person in a brand without a
             * round trip.
             */
            if ($withConsent) {
                return $this->invitePending($client, $existing->email, $role, $permissions, $invitedBy);
            }

            $this->attach($client, $existing, $role, $permissions);

            return [
                'user' => $existing,
                'password' => null,
                'delivered' => false,
                'existed' => true,
                'pending' => false,
            ];
        }

        $temporaryPassword = Str::password(14, symbols: false);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => Hash::make($temporaryPassword),
        ]);

        $this->attach($client, $user, $role, $permissions);

        return [
            'user' => $user,
            'password' => $temporaryPassword,
            'delivered' => $this->sendSetupLink($user, $client),
            'existed' => false,
            'pending' => false,
        ];
    }

    /**
     * Ask the person before writing anything — queued item B.
     *
     * Nothing reaches `brand_user` here. The row holds what they WOULD get,
     * and the pivot is written at acceptance and not one moment earlier.
     *
     * ⚠️ IT BURNS TIME ON PURPOSE. The other branch runs Hash::make(), which is
     * bcrypt and deliberately slow — roughly a tenth of a second. Skipping it
     * here would make "this address has an account" measurable with a
     * stopwatch, and a leak you can time is still a leak. `equaliseTiming()`
     * does the same work and throws it away.
     *
     * ⚠️ AN ALREADY-PENDING INVITATION IS REUSED, not duplicated. Two live
     * tokens for one address on one brand means a second mail that looks like
     * the first, and an accepted invitation with a twin still open.
     *
     * @param  array<string, string>  $permissions
     * @return array{user: ?User, password: ?string, delivered: bool, existed: bool, pending: bool}
     */
    private function invitePending(
        Client $client,
        string $email,
        UserRole $role,
        array $permissions,
        ?User $invitedBy,
    ): array {
        $this->equaliseTiming();

        $invitation = BrandInvitation::where('client_id', $client->id)
            ->where('email', $email)
            ->get()
            ->first(fn (BrandInvitation $i) => $i->isPending());

        $invitation ??= BrandInvitation::create([
            'client_id' => $client->id,
            'invited_by' => $invitedBy?->getKey(),
            'email' => $email,
            'role' => $this->brandRole($role),
            'permissions' => $permissions,
            'token' => BrandInvitation::freshToken(),
            'expires_at' => now()->addDays(BrandInvitation::LIFETIME_DAYS),
        ]);

        return [
            'user' => null,
            'password' => null,
            // Never throws, for the same reason sendSetupLink() does not: a
            // dead mail server must not lose the invitation that was just made.
            'delivered' => $this->notifyInvited($invitation),
            'existed' => true,
            'pending' => true,
        ];
    }

    /**
     * Spend what the other branch spends on hashing, and discard it.
     *
     * ⚠️ NOT SUPERSTITION. Both paths are one insert plus one synchronous mail;
     * the only asymmetry left is bcrypt, and bcrypt is slow by design. Without
     * this, "existing account" and "new account" differ by a measurable
     * constant on every invite.
     */
    private function equaliseTiming(): void
    {
        Hash::make(Str::password(14, symbols: false));
    }

    /** @return bool whether the mail went out */
    private function notifyInvited(BrandInvitation $invitation): bool
    {
        try {
            $recipient = User::where('email', $invitation->email)->first();

            if ($recipient === null) {
                return false;
            }

            $recipient->notify(new BrandMembershipInvitation($invitation));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Accept an invitation: write the membership it was holding.
     *
     * ⚠️ THE ONLY PATH FROM AN INVITATION TO A PIVOT ROW. Marked accepted in
     * the same breath as the attach, so a token cannot be replayed into a
     * second brand or used after the person was removed again.
     */
    public function accept(BrandInvitation $invitation, User $user): void
    {
        $this->attach($invitation->client, $user, $invitation->role, $invitation->permissions ?? []);

        $invitation->forceFill(['accepted_at' => now()])->save();
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
        // ⚠️ THE BRAND IS PASSED IN, ALWAYS. An account belongs to several, so
        // "their brand" stopped being a question a user row can answer — and
        // since 2026-09-15 there is no column left to guess from. Every caller
        // that has a brand names it; the nullable signature is what makes a
        // caller without one fail closed rather than mail the wrong brand.
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
