<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * How many assistant turns may be in flight at once, for the whole account.
 *
 * ⚠️ THIS PROTECTS THE PUBLIC SITE, NOT THE ASSISTANT. Everything on this
 * hosting account — vamosdebreakfast.com, the portal, /admin — is served by one
 * small pool of PHP workers. A turn that reads a brandbook occupies one of them
 * for the better part of a minute, and once enough are held the front end stops
 * waiting and answers everything else with a small-body 503: the marketing
 * site, a client's dashboard, a login. Measured on 2026-08-18 — requests to the
 * public site were refused in 15 seconds flat while three long requests were
 * running, and the whole failure is invisible because the worker dies before
 * Laravel's exception handler ever runs, so nothing reaches laravel.log.
 *
 * A RATE LIMIT CANNOT DO THIS JOB, which is why this exists beside the
 * `throttle` middleware rather than instead of it. Rate limits count requests
 * per minute; the thing that hurts is how many are running AT ONCE. Twenty
 * ninety-second requests inside one minute is within `throttle:20,1` and is
 * also every worker gone.
 *
 * The lock is released in a finally, and carries a TTL as well, because a
 * worker killed mid-turn — the exact failure this guards against — never gets
 * to run its own cleanup. Without the TTL the first crash would wedge the
 * assistant shut until somebody cleared the cache by hand.
 *
 * Answers 429 with a sentence a person can act on. Not 503: the service is
 * fine, it is busy, and telling somebody to wait ten seconds is the difference
 * between them waiting and them pressing send again — which is what turns one
 * slow turn into a pool with nothing left in it.
 */
class LimitConcurrentAiTurns
{
    /**
     * Two at a time.
     *
     * Enough for two people at Breakfast to be working, few enough that the
     * rest of the pool is left for everybody who is only reading a page. Three
     * concurrent short requests were measured fine; the number is small because
     * what it bounds is the LONG ones.
     */
    private const CONCURRENT = 2;

    /**
     * Longer than any turn should take and shorter than the ~180s at which this
     * host kills a request outright, so a lock outlives the request it belongs
     * to but never outlives the worker holding it.
     */
    private const TTL_SECONDS = 150;

    public function handle(Request $request, Closure $next): Response
    {
        $held = $this->acquire();

        if ($held === null) {
            return response()->json([
                'error' => 'El asistente está atendiendo otra consulta. '
                    .'Espera unos segundos y vuelve a enviarlo.',
            ], 429);
        }

        try {
            return $next($request);
        } finally {
            // Whatever happened — an answer, an exception, a provider timeout —
            // the slot goes back. Only an outright kill skips this, and the TTL
            // is what covers that case.
            $held->release();
        }
    }

    /**
     * The first free slot, or null when they are all taken.
     *
     * Numbered locks rather than a counter: a counter has to be read, compared
     * and written, and two requests arriving together both read the same number.
     * `Cache::lock()->get()` is atomic on every store, so the race cannot happen
     * — and CACHE_STORE is `database` here, which supports it without Redis.
     */
    private function acquire(): ?Lock
    {
        for ($slot = 1; $slot <= self::CONCURRENT; $slot++) {
            $lock = Cache::lock("ai-turn-slot-{$slot}", self::TTL_SECONDS);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
