<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /admin area to Breakfast staff.
 *
 * Returns 404 rather than 403 on purpose: a client user probing /admin
 * learns nothing about whether the area exists.
 */
class EnsureUserIsBreakfast
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isBreakfast(), 404);

        return $next($request);
    }
}
