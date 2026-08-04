<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Sends people where they actually work: Breakfast staff to /admin,
 * client users to /portal. Replaces Fortify's single fixed home path.
 *
 * Bound in App\Providers\FortifyServiceProvider.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        /** @var Request $request */
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended($request->user()->homeRoute());
    }
}
