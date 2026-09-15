<?php

use App\Http\Middleware\EnsurePortalSectionAccess;
use App\Http\Middleware\EnsureStaffCoversClient;
use App\Http\Middleware\EnsureUserIsBreakfast;
use App\Http\Middleware\LimitConcurrentAiTurns;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'breakfast' => EnsureUserIsBreakfast::class,
            // Runs on every /admin route and no-ops unless one names a {client}.
            'covers-client' => EnsureStaffCoversClient::class,
            'section' => EnsurePortalSectionAccess::class,
            // Bounds how many assistant turns run AT ONCE, which a rate limit
            // cannot do. Every AI route carries it — see the middleware.
            'ai-turn' => LimitConcurrentAiTurns::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * ⚠️ `api/*` ALONE IS NOT ENOUGH, and the missing half cost a day.
         *
         * Every JavaScript-driven endpoint in this app lives under `admin/` or
         * `portal/` — the two assistants, the onboarding turn, the checklist.
         * Narrowing this to `api/*` meant a failure on one of those answered
         * with Laravel's HTML error page, so `fetch` got `text/html` where it
         * asked for JSON, `response.json()` threw, and the panel showed
         * whatever its fallback happened to be.
         *
         * That is exactly how a client sat in front of "No obtuve respuesta."
         * on 2026-09-15: an eight-hour session expired, every question came
         * back 419 wearing the "Page Expired" page, and because the request
         * died at the CSRF middleware nothing was logged, nothing was charged
         * and no turn was stored. Four silent logs, one unreadable response.
         *
         * `expectsJson()` is Laravel's own default and asks the only question
         * that matters: did the caller ask for JSON? A browser navigating to a
         * page sends `Accept: text/html` and still gets the HTML error screen,
         * which is what that screen is for.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
