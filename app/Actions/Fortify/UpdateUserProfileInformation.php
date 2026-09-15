<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Your own name, and nothing else.
 *
 * The address is not part of what you edit about yourself. Nobody signs up
 * here — Breakfast creates the account and mails the invitation — so the
 * address is the account's identity rather than a preference on it: it is the
 * username, the only way back in through a reset link, and the record a
 * client's brand is reached by.
 *
 * Fortify's stock version accepts an email and, on a User that implements
 * MustVerifyEmail, re-sends verification. Both are gone: an endpoint that
 * quietly accepts a field the form does not show is a rule that holds only as
 * long as nobody crafts the request.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            // 120 is what the team and invitation forms have always accepted.
            'name' => ['required', 'string', 'max:120'],
        ], [], ['name' => 'nombre'])->validateWithBag('updateProfileInformation');

        $user->forceFill(['name' => $input['name']])->save();
    }
}
