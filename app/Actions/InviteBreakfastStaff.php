<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Add someone to Breakfast and send them the link to set a password.
 *
 * The client-side twin of this is InviteUserToClient. They stay apart because
 * the two accounts are not the same thing: staff have no client and no
 * permission map — being Breakfast is the permission, see User::accessTo().
 *
 * The account is created with a random password nobody needs to know; the
 * temporary one is returned so the caller can read it out if the mail does not
 * arrive.
 */
class InviteBreakfastStaff
{
    /** @return array{user: User, password: string, delivered: bool} */
    public function handle(string $name, string $email, UserRole $role): array
    {
        $temporaryPassword = Str::password(14, symbols: false);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'client_id' => null,
            'password' => Hash::make($temporaryPassword),
            'permissions' => [],
        ]);

        return [
            'user' => $user,
            'password' => $temporaryPassword,
            'delivered' => $this->sendSetupLink($user),
        ];
    }

    /**
     * The token is minted directly rather than through sendResetLink() so the
     * wording is ours and so re-inviting is not silently swallowed by the
     * broker's one-per-minute throttle.
     *
     * A mail failure must not lose the account that was just created, so this
     * never throws; the caller falls back to the temporary password.
     */
    public function sendSetupLink(User $user): bool
    {
        try {
            $user->notify(new StaffInvitation(Password::broker()->createToken($user)));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
