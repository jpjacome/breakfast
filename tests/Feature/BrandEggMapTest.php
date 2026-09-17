<?php

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;

/*
 * /brand-egg — the map of which entregable feeds which layer.
 *
 * Two things here are decisions rather than behaviour, and both fail silently
 * if they regress: the page is reachable WITHOUT a login, and it is kept out of
 * search. Nothing on screen would look wrong either way, so they are pinned.
 */

it('opens without a login', function () {
    $this->get('/brand-egg')
        ->assertOk()
        ->assertSee('Las cinco capas del Brand Egg');
});

it('tells search engines to stay away', function () {
    // "Unlisted" is this meta tag plus the fact that nothing links here. The
    // page publishes Breakfast's 48 entregables, so being indexable is the
    // difference between sending someone a link and publishing a method.
    $this->get('/brand-egg')->assertSee('noindex, nofollow', false);
});

it('names every layer and every entregable that feeds one', function () {
    $response = $this->get('/brand-egg');

    foreach (BrandEggLayer::cases() as $layer) {
        $response->assertSee($layer->label());

        foreach ($layer->sources() as $item) {
            // The label AND the column name: on this screen the identity of the
            // two is the point, so both are printed.
            //
            // ⚠️ ESCAPED, i.e. no `false` second argument. "Do's and don'ts de
            // influencers" reaches the page as &#039;, so asserting the raw
            // label fails on the one entregable with an apostrophe in it and
            // passes on the other 47.
            $response->assertSee($item->label());
            $response->assertSee($item->value, false);
        }
    }
});

it('accounts for all 48 entregables, whether or not they feed a layer', function () {
    // The screen's whole claim is that it is derived rather than transcribed, so
    // an entregable added to the enum has to appear here without anyone editing
    // the blade. Checking every one of the 48 is what makes that true.
    $response = $this->get('/brand-egg');

    foreach (DeliverableItem::cases() as $item) {
        $response->assertSee($item->label());
    }
});

it('draws no ring as pending, because layer 4 was settled', function () {
    // ⚠️ THIS TEST DID ITS JOB. It used to assert the opposite — that layer 4
    // had two sources, did not include Emblemas, and was hatched "sin resolver"
    // — and its comment said that whoever resolved "Brand Assets" should be
    // told by this test to stop drawing it as pending. That happened on
    // 2026-09-16, and the failure was the message arriving.
    expect(BrandEggLayer::Assets->sources())
        ->toContain(DeliverableItem::Emblemas)
        ->and(BrandEggLayer::Assets->isInventory())->toBeTrue();

    $this->get('/brand-egg')
        ->assertDontSee('sin resolver', false)
        // The files reach the layer as TEXT, and the screen names the column
        // rather than implying the Egg looks at pictures.
        ->assertSee('brand_assets.visual_reading', false);
});

it('is not linked from the public site', function () {
    // Unlisted means unlinked. If a nav entry ever appears, this fails and the
    // decision gets made again on purpose rather than by accident.
    foreach (['/', '/nosotros', '/servicios', '/contacto'] as $page) {
        $this->get($page)->assertDontSee('/brand-egg', false);
    }
});
