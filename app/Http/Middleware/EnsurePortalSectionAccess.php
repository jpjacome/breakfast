<?php

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a portal route on one section.
 *
 *   ->middleware('section:estrategia')          must be able to read it
 *   ->middleware('section:estrategia,write')    must be able to change it
 *
 * Hiding a section from the sidebar is presentation; this is the part that
 * actually stops someone who types the URL.
 *
 * 404 rather than 403, matching EnsureUserIsBreakfast: a member who was never
 * given Reuniones should not learn that their brand has meetings.
 */
class EnsurePortalSectionAccess
{
    public function handle(Request $request, Closure $next, string $section, string $level = 'read'): Response
    {
        $target = PortalSection::tryFrom($section);
        $needed = AccessLevel::tryFrom($level);

        // A typo in a route definition must not silently open the page.
        abort_if($target === null || $needed === null, 500, "Unknown portal section [{$section}:{$level}].");

        $access = $request->user()?->accessTo($target);

        abort_unless($access?->covers($needed) ?? false, 404);

        return $next($request);
    }
}
