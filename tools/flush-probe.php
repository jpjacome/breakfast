<?php

/**
 * What this host actually allows — measured, not guessed.
 *
 * THREE QUESTIONS, ONE UPLOAD, because each one costs a trip to the FTP client
 * and a file left sitting on a live server:
 *
 *   mode=info    what PHP says about itself. Instant, harmless.
 *   mode=flush   does output reach the browser incrementally? Decides whether
 *                streaming answers — and therefore a REAL stop button — are
 *                possible at all (CLAUDE.md §7).
 *   mode=hold    hold one PHP worker for N seconds and report which process
 *                served it. Fire several at once and the PIDs and start times
 *                say how many workers the pool actually has, which is the
 *                number item 9 of the cycle asks for.
 *
 * ⚠️ IT LIVES IN tools/ AND NOT IN public/. Anything under public/ is live the
 * moment it is uploaded. This sits where the web server cannot reach it, so the
 * repo carries the diagnostic without the server ever serving it by accident.
 * You upload it to run it, and you take it straight back off.
 *
 * ⚠️ TOKEN-GATED AND CAPPED, WHICH limite.php WAS NOT. That August probe sat
 * publicly reachable into September, allocating 512 MB to anyone who found it,
 * on a worker pool shared with every other site on the account. This one
 * refuses without a secret and will not hold a worker longer than HOLD_MAX, so
 * a crawler that stumbles on it cannot turn it into an outage. It is still a
 * held worker: DELETE IT WHEN YOU ARE DONE.
 *
 * ⚠️ THE POOL IS SHARED WITH THE PUBLIC MARKETING SITE and three sibling
 * projects. Holding workers is exactly what makes the front end answer 503 for
 * everything behind it — so ramp up slowly, keep holds short, and stop at the
 * first 503 rather than pushing on for a rounder number.
 *
 * ── HOW TO RUN ────────────────────────────────────────────────────────────
 *
 *  1. Change TOKEN below.
 *  2. FTP to  public_html/drpixel/breakfast/public/probe.php
 *     ⚠️ NOT public_html/ — that is the account root, a different site.
 *  3. Check it answers:
 *       curl -s "https://vamosdebreakfast.com/probe.php?t=TOKEN&mode=info"
 *  4. Hand the URL over and the timings get driven from there.
 *  5. DELETE public_html/drpixel/breakfast/public/probe.php
 */
const TOKEN = 'change-me-before-uploading';

/** No hold may exceed this, whatever the query string asks for. */
const HOLD_MAX = 12;

if (! hash_equals(TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}

$started = microtime(true);
$mode = $_GET['mode'] ?? 'info';

/* ---------------------------------------------------------------------------
   mode=info — what PHP says about itself
   --------------------------------------------------------------------------- */

if ($mode === 'info') {
    header('Content-Type: text/plain; charset=utf-8');

    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

    echo 'php            ', PHP_VERSION, "\n";
    echo 'sapi           ', PHP_SAPI, "\n";
    echo 'pid            ', getmypid(), "\n";
    echo 'memory_limit   ', ini_get('memory_limit'), "\n";
    echo 'max_execution  ', ini_get('max_execution_time'), "\n";
    echo 'post_max_size  ', ini_get('post_max_size'), "\n";
    echo 'upload_max     ', ini_get('upload_max_filesize'), "\n";
    echo 'zlib.output    ', ini_get('zlib.output_compression') ?: '0', "\n";
    echo 'output_buffer  ', ini_get('output_buffering') ?: '0', "\n";
    echo 'loadavg        ', $load ? implode(' ', array_map(fn ($n) => round($n, 2), $load)) : 'n/a', "\n";
    echo 'server         ', $_SERVER['SERVER_SOFTWARE'] ?? 'n/a', "\n";
    exit;
}

/* ---------------------------------------------------------------------------
   mode=hold — hold one worker, and say which one
   ---------------------------------------------------------------------------

   The whole measurement is in the three numbers it returns. Fire N of these at
   once from one machine and compare:

     · different PIDs          → they really did run in parallel
     · the SAME pid twice      → they were served one after another, so the
                                 pool was already full
     · staggered `began` times → requests queued rather than ran together, and
                                 the stagger IS the queue

   A 503 instead of a reply means the front end gave up before PHP was reached
   at all — which is the failure the public site shows, and the point to stop.
   --------------------------------------------------------------------------- */

if ($mode === 'hold') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');

    $seconds = min(HOLD_MAX, max(1, (int) ($_GET['s'] ?? 3)));
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['id'] ?? '?'));

    // Wall clock as well as sleep(), so a worker that is descheduled still
    // reports how long it really held rather than what it asked for.
    $beganAt = microtime(true);
    sleep($seconds);
    $endedAt = microtime(true);

    printf("id=%s pid=%d began=%.3f ended=%.3f held=%.3f\n",
        $id, getmypid(), $beganAt, $endedAt, $endedAt - $beganAt);
    exit;
}

/* ---------------------------------------------------------------------------
   mode=flush — does output arrive incrementally?
   ---------------------------------------------------------------------------

   Lines one per second        → flushing WORKS; streaming is possible.
   Everything at once at ~10s  → BUFFERED. There is an openresty proxy in front
                                 of PHP with gzip on (confirmed 2026-09-15) and
                                 it is holding the whole response.

   Run it twice: with padding (default) and with &raw=1. If only the padded one
   trickles, something downstream simply needs a few KB before it forwards
   anything — a different answer from "buffered", and the one that makes people
   abandon streaming for the wrong reason.
   --------------------------------------------------------------------------- */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// The nginx/openresty opt-out, and the reason there is any hope here at all.
header('X-Accel-Buffering: no');
header('Content-Encoding: none');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

while (ob_get_level() > 0) {
    @ob_end_flush();
}

ob_implicit_flush(true);

$padded = ! isset($_GET['raw']);

echo 'flush probe — pid ', getmypid(), ' — ', date('c'), "\n";
echo $padded ? "padding: on (2KB per chunk)\n" : "padding: off\n";
echo str_repeat('-', 60), "\n";
flush();

for ($i = 1; $i <= 10; $i++) {
    printf("chunk %2d  at %5.2fs\n", $i, microtime(true) - $started);

    if ($padded) {
        echo '# ', str_repeat('.', 2048), "\n";
    }

    flush();
    sleep(1);
}

printf("done after %.2fs — one line per second means flushing works\n",
    microtime(true) - $started);
