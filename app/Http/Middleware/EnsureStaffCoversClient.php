<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a Breakfast user out of a brand they were not put on.
 *
 * Applied to the whole /admin group rather than route by route, and a no-op on
 * routes with no {client} in them. That is the point: the next brand-scoped
 * route somebody adds is covered the day it is written, instead of depending
 * on them remembering a middleware. Everything under /admin that names a brand
 * goes through here — the brand profile, its context files, its users, the
 * onboarding assistant.
 *
 * 404 rather than 403, matching EnsureUserIsBreakfast: someone probing brand
 * slugs learns nothing about which ones exist.
 */
class EnsureStaffCoversClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->route('client');

        if ($client instanceof Client) {
            abort_unless($request->user()?->covers($client), 404);
        }

        return $next($request);
    }
}
