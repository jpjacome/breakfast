<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * A first sign-in is the verification.
 *
 * There is no sign-up here and no "confirm your address" mail: Breakfast
 * creates the account and mails an invitation, and the only way to a password
 * is the link inside it. So reaching the app at all means the address
 * delivered — running a separate verification round trip would ask people to
 * prove twice what the invitation already proved once.
 *
 * Which makes email_verified_at mean something the roster can use: a staff
 * member still carrying null has never signed in.
 *
 * On the Login event rather than in LoginResponse so it covers every way a
 * session starts — the password form, the second-factor challenge, and a
 * remembered browser — not just Fortify's redirect.
 */
class MarkEmailVerifiedOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // Null-checked, so a remembered browser firing Login on a later
        // request is a no-op rather than a write.
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
