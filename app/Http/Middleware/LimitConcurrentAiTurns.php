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
 * ⚠️ THIS PROTECTS FOUR OTHER SITES, NOT THE ASSISTANT. Everything on this
 * hosting account is served from ONE allowance of 30 concurrent requests —
 * this portal, the public marketing site, and three sibling projects that know
 * nothing about us. A turn that reads a brandbook occupies one of those 30 for
 * the better part of a minute, and when they are all held CloudLinux refuses
 * everything else: the marketing site, a client's dashboard, a login, and the
 * neighbours' sites too.
 *
 * ⚠️ RE-MEASURED 2026-09-16, and the numbers in the first version of this
 * comment were wrong in both directions (CLAUDE.md §3 has the full report):
 *
 *   · the ceiling is **30 concurrent, not 3** — ten times what was assumed;
 *   · over it the status is **508 in ~0.6s**, not a 503 after 15 seconds.
 *
 * The failure is still invisible, and that has not changed: 508 is CloudLinux
 * refusing the request before PHP runs, so no Laravel handler, no laravel.log
 * line, no ai_usage_logs row.
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
     * Five at a time.
     *
     * ⚠️ IT BOUNDS DURATION, NOT POPULARITY, which is why it stays far below
     * the ceiling. Page loads take about a second and cost nothing; an AI turn
     * holds its slot for ten to ninety seconds. Thirty simultaneous turns would
     * take the whole account — this portal, the marketing site and three
     * neighbouring projects — off the air for a minute and a half.
     *
     * Was 2, raised 2026-09-16 once the real ceiling was measured at 30. The
     * old number was chosen against an assumed ceiling of 3 and was therefore
     * about ten times stricter than it needed to be.
     *
     * ⚠️ FIVE RATHER THAN SOMETHING LARGER, and the reason is a gap in the
     * measurement: the 30 was proved with a 1.4KB script, while 10 concurrent
     * **Laravel** requests are the most ever proved good. A real request boots
     * the framework, and LVE caps physical memory as well as processes — a
     * limit nobody has measured. Five sits comfortably under the proven figure.
     * Raising it further wants PMEM answered first (cPanel → Resource Usage).
     *
     * Public so the test can hold every slot without copying the number; this
     * class decides how many there are.
     */
    public const CONCURRENT = 5;

    /**
     * Longer than any turn should take, so a lock outlives the request it
     * belongs to but never outlives the worker holding it.
     *
     * ⚠️ 150 IS PROBABLY TOO LONG NOW, and it is left alone deliberately until
     * one cheap test settles it. It was picked against the ~180s at which the
     * front end kills a request — but PHP's own max_execution_time on this host
     * is **60s** (measured 2026-09-16). If a killed worker can only ever have
     * lived 60 seconds, a stale lock sits for 90 seconds longer than it can
     * possibly need to.
     *
     * What stops it being a simple fix: PHP does not count socket waits toward
     * max_execution_time, and a turn is almost entirely a socket wait, so a
     * request may legitimately run past 60s of wall clock. Until a probe that
     * holds ~70s says which it is, a TTL that is too long is the safe error —
     * too short would release a slot while its turn is still running.
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
