<?php

/**
 * Does this host let PHP flush incrementally?
 *
 * The one question that decides whether streaming answers — and therefore a
 * REAL cancel button — are possible on iFastNet. If output only reaches the
 * browser when the script ends, a "stop" button can never be more than a lie:
 * the worker keeps running, the provider keeps generating and billing, and the
 * abandoned answer still gets written to the thread.
 *
 * ⚠️ WHY IT IS IN tools/ AND NOT IN public/. Anything under public/ is live the
 * moment it is uploaded. This file is deliberately somewhere the web server
 * cannot reach, so the repo can carry the diagnostic without the server ever
 * serving it by accident. You upload it to public/ to run it, and you take it
 * straight back off.
 *
 * ⚠️ AND IT IS TOKEN-GATED, which limite.php was not. That August probe sat
 * publicly reachable into September, allocating 512 MB to anyone who found it,
 * on a worker pool shared with every other domain on the account. A probe that
 * refuses to run without a secret cannot be stumbled into by a crawler while
 * you forget about it — but it is still a worker held for ten seconds, so
 * DELETE IT WHEN YOU ARE DONE.
 *
 * ── HOW TO RUN ────────────────────────────────────────────────────────────
 *
 *  1. Edit TOKEN below to something only you know.
 *  2. FTP this file to  public_html/drpixel/breakfast/public/flush-probe.php
 *
 *     ⚠️ NOT public_html/. That is the account root, which is somebody else's
 *     WordPress site — this project lives in a SUBDIRECTORY of a shared
 *     account. See CLAUDE.md §3.
 *
 *  3. From your machine — the -N is what matters, it tells curl not to buffer:
 *
 *       curl -N "https://vamosdebreakfast.com/flush-probe.php?t=YOURTOKEN"
 *
 *     and again with the proxy-buffering hint turned off, to see if the
 *     headers are what is being ignored:
 *
 *       curl -N "https://vamosdebreakfast.com/flush-probe.php?t=YOURTOKEN&raw=1"
 *
 *  4. DELETE public_html/drpixel/breakfast/public/flush-probe.php.
 *
 * ── HOW TO READ IT ────────────────────────────────────────────────────────
 *
 *  Lines trickle out one per second   → flushing WORKS. Streaming and a real
 *                                       cancel are possible.
 *  Everything appears at once at ~10s → BUFFERED. There is an openresty proxy
 *                                       in front of PHP with gzip on
 *                                       (confirmed 2026-09-15), and it is
 *                                       holding the whole response. Streaming
 *                                       is off the table unless the headers
 *                                       below can defeat it.
 *
 *  If `raw=1` trickles and the default does not, the padding is what matters —
 *  something downstream has a minimum buffer size. If neither trickles, the
 *  proxy is buffering regardless and no application-level fix will change it.
 */
const TOKEN = 'Psycho2psychote';

if (($_GET['t'] ?? '') !== TOKEN) {
    http_response_code(404);
    exit;
}

$started = microtime(true);

/*
 * Everything that is known to defeat incremental output, turned off.
 *
 * X-Accel-Buffering is the nginx/openresty one and the reason there is any
 * hope here at all: it asks the proxy not to buffer this particular response.
 * If the trickle only happens WITH these headers, that tells you exactly what
 * the real streaming endpoint has to send.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

// Ask for no compression. gzip buffers by its own block size, so it defeats
// flushing independently of the proxy.
header('Content-Encoding: none');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

// Tear down every buffer PHP itself is holding, however many layers deep.
while (ob_get_level() > 0) {
    @ob_end_flush();
}

ob_implicit_flush(true);

/*
 * Padding, because a buffer downstream may simply not forward anything until
 * it has a few KB. Without this, a host that CAN flush looks exactly like one
 * that cannot — which is the trap that makes people give up on streaming for
 * the wrong reason. `raw=1` turns it off so the two cases can be told apart.
 */
$padded = ! isset($_GET['raw']);

echo 'flush probe — ', date('c'), "\n";
echo 'php ', PHP_VERSION, ' / sapi ', PHP_SAPI, "\n";
echo $padded ? "padding: on (2KB per chunk)\n" : "padding: off\n";
echo str_repeat('-', 60), "\n";
flush();

for ($i = 1; $i <= 10; $i++) {
    printf("chunk %2d  at %5.2fs\n", $i, microtime(true) - $started);

    if ($padded) {
        // Comment-shaped so it is obvious in the output what it is for.
        echo '# ', str_repeat('.', 2048), "\n";
    }

    flush();
    sleep(1);
}

printf("done after %.2fs — if these arrived one per second, flushing works\n",
    microtime(true) - $started);
