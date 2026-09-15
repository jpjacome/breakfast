<?php

declare(strict_types=1);

/**
 * The site names ONE home, whichever door you came through.
 *
 * ⚠️ WHY THIS EXISTS. The canonical tag was built with url()->current(), which
 * reads the host off the incoming request — so every alias served a canonical
 * naming ITSELF, and the tag meant to consolidate duplicates certified each
 * copy as an original instead. This site really is reachable on more than one
 * hostname (vamosdebreakfast.com and breakfast.drpixel.app share one document
 * root) and robots.txt allows everything, so it was two fully indexable copies.
 *
 * The failure is invisible from a browser — you only ever see the host you
 * typed, and it looks correct from every one of them. It takes a request from
 * a second host to see it at all, which is exactly what these do.
 */
beforeEach(function () {
    config()->set('app.url', 'https://vamosdebreakfast.com');
});

it('names the canonical host even when reached through another one', function () {
    $response = $this->get('http://breakfast.drpixel.app/nosotros');

    $response->assertOk()
        ->assertSee('<link rel="canonical" href="https://vamosdebreakfast.com/nosotros">', false)
        ->assertSee('<meta property="og:url" content="https://vamosdebreakfast.com/nosotros">', false)
        // The give-away of the old behaviour, asserted directly: if the alias
        // ever appears in a canonical again, it is this bug returning.
        ->assertDontSee('canonical" href="https://breakfast.drpixel.app', false);
});

it('uses the canonical host on the home page too', function () {
    // getPathInfo() is '/' here, so this is also the guard against the root
    // coming out as a bare domain with no slash, or as a doubled one.
    $this->get('http://breakfast.drpixel.app/')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://vamosdebreakfast.com/">', false);
});

it('leaves a query string out of the canonical', function () {
    // A tracking parameter must not mint a second "original" of a page that
    // has not changed — which is the whole reason it is getPathInfo() and not
    // getRequestUri().
    $this->get('https://vamosdebreakfast.com/servicios?utm_source=instagram')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://vamosdebreakfast.com/servicios">', false);
});

it('still names a canonical on the entrance screens, which are noindex', function () {
    // The six entrance screens carry noindex, and that is what keeps them out
    // of an index — the canonical is harmless beside it. Pinned so that adding
    // noindex to a page never silently drops its canonical as well.
    $this->get('http://breakfast.drpixel.app/login')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertSee('<link rel="canonical" href="https://vamosdebreakfast.com/login">', false);
});
