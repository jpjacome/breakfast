<?php

declare(strict_types=1);

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Enums\LayerItemState;
use App\Models\BrandEggMessage;
use App\Models\Client;
use App\Services\BrandEgg\LayerItem;
use App\Services\BrandEgg\LayerProgress;

/**
 * The checklist the Egg assistant opens every layer with.
 *
 * What is pinned here is the three things it would be easy to get subtly wrong
 * and never notice: that a tick comes from the column and not from the
 * conversation, that ➖ "no aplica" can only ever land on an OPTIONAL, and that
 * an entregable filled for an earlier layer says where it came from instead of
 * being asked for a second time.
 *
 * @see docs/brand-egg.md §14.3
 */
beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Panadería Sur']);
});

/** Fill one entregable, the way accepting a card does. */
function fillEntregable(Client $client, DeliverableItem $item, string $text): void
{
    $client->deliverables()->firstOrNew()->fill([$item->value => $text])->save();
    $client->refresh();
}

/** Record an assistant turn that asked about these entregables. */
function askedAbout(Client $client, BrandEggLayer $layer, DeliverableItem ...$items): void
{
    BrandEggMessage::create([
        'client_id' => $client->id,
        'role' => 'assistant',
        'body' => 'Una pregunta.',
        'layer' => $layer,
        'questions' => array_map(fn (DeliverableItem $i) => $i->value, $items),
    ]);
}

/** @return array<string, LayerItem> */
function linesOf(LayerProgress $progress): array
{
    $out = [];

    foreach ($progress->items as $line) {
        $out[$line->item->value] = $line;
    }

    return $out;
}

it('opens a layer with every source pending', function () {
    $progress = LayerProgress::for($this->client, BrandEggLayer::Esencia);

    expect($progress->items)->toHaveCount(6)
        ->and($progress->filledCount())->toBe(0)
        ->and($progress->isSettled())->toBeFalse()
        ->and($progress->isComposable())->toBeFalse();

    foreach ($progress->items as $line) {
        expect($line->state)->toBe(LayerItemState::Pending);
    }
});

it('asks for the relato first, because the other five lean on it', function () {
    // ⚠️ sources() order is load-bearing, not alphabetical. If somebody
    // reorders the enum this fails, which is the point.
    expect(LayerProgress::for($this->client, BrandEggLayer::Esencia)->next())
        ->toBe(DeliverableItem::Relato);
});

it('ticks from the column, and moves on to the next required one', function () {
    fillEntregable($this->client, DeliverableItem::Relato, 'Nace de una abuela.');

    $progress = LayerProgress::for($this->client, BrandEggLayer::Esencia);

    expect(linesOf($progress)[DeliverableItem::Relato->value]->state)
        ->toBe(LayerItemState::Filled)
        ->and($progress->filledCount())->toBe(1)
        ->and($progress->next())->toBe(DeliverableItem::BrandPromise);
});

it('never offers an optional while an obligatorio is still blank', function () {
    // Manifesto and Claim are the two opcionales of layer 1. Asking for a
    // manifesto before the brand has a promise is the sequencing this pins.
    foreach ([DeliverableItem::Relato, DeliverableItem::BrandPromise] as $item) {
        fillEntregable($this->client, $item, 'Algo.');
    }

    expect(LayerProgress::for($this->client, BrandEggLayer::Esencia)->next())
        ->toBe(DeliverableItem::BrandStatement);
});

it('reads an asked-and-still-empty optional as no aplica', function () {
    askedAbout($this->client, BrandEggLayer::Esencia, DeliverableItem::Manifesto);

    $line = linesOf(LayerProgress::for($this->client, BrandEggLayer::Esencia))[DeliverableItem::Manifesto->value];

    expect($line->state)->toBe(LayerItemState::Skipped)
        ->and($line->state->isSettled())->toBeTrue()
        ->and($line->line())->toContain('➖');
});

it('refuses to let a question settle an obligatorio', function () {
    /*
     * ⚠️ THE ONE THAT MATTERS. If asking could settle any entregable, the
     * assistant could talk a layer into looking finished without a single
     * column moving — the checklist agreeing with the conversation instead of
     * with the database, which is the failure it exists to prevent.
     */
    askedAbout($this->client, BrandEggLayer::Esencia, DeliverableItem::Relato);

    $progress = LayerProgress::for($this->client, BrandEggLayer::Esencia);

    expect(linesOf($progress)[DeliverableItem::Relato->value]->state)
        ->toBe(LayerItemState::Pending)
        ->and($progress->next())->toBe(DeliverableItem::Relato);
});

it('settles a layer once the obligatorios are in and the optionals were offered', function () {
    foreach (BrandEggLayer::Esencia->sources() as $item) {
        if ($item->isRequired()) {
            fillEntregable($this->client, $item, 'Algo.');
        }
    }

    $progress = LayerProgress::for($this->client, BrandEggLayer::Esencia);

    // Composable already — a layer with every obligatorio can say something
    // honest, and waiting for a Manifesto the brand will never have would
    // leave it uncomposed forever (§2 Finding 3).
    expect($progress->isComposable())->toBeTrue()
        ->and($progress->isSettled())->toBeFalse();

    askedAbout($this->client, BrandEggLayer::Esencia, DeliverableItem::Manifesto, DeliverableItem::Claim);

    expect(LayerProgress::for($this->client, BrandEggLayer::Esencia)->isSettled())->toBeTrue();
});

it('says where an entregable came from instead of asking twice', function () {
    // Valores feeds layers 1, 2 and 5; relato feeds 1, 3 and 4.
    fillEntregable($this->client, DeliverableItem::Valores, 'Oficio, barrio, paciencia.');

    $line = linesOf(LayerProgress::for($this->client, BrandEggLayer::Personalidad))[DeliverableItem::Valores->value];

    expect($line->filledEarlierIn)->toBe(BrandEggLayer::Esencia)
        ->and($line->line())->toContain('Esencia');

    // Layer 2 therefore opens with a single question, which is most of what
    // makes it feel like progress rather than a form.
    expect(LayerProgress::for($this->client, BrandEggLayer::Personalidad)->next())
        ->toBe(DeliverableItem::Arquetipos);
});

it('does not claim an earlier layer for the layer that owns the entregable', function () {
    fillEntregable($this->client, DeliverableItem::Relato, 'Nace de una abuela.');

    expect(linesOf(LayerProgress::for($this->client, BrandEggLayer::Esencia))[DeliverableItem::Relato->value]->filledEarlierIn)
        ->toBeNull();
});

it('carries a refusal across layers, since manifesto feeds one and five', function () {
    // Declined while building the yolk. Being asked again on the outer ring
    // because the first refusal was filed under another layer is exactly the
    // repetition the checklist exists to prevent.
    askedAbout($this->client, BrandEggLayer::Esencia, DeliverableItem::Manifesto);

    expect(linesOf(LayerProgress::for($this->client, BrandEggLayer::Universo))[DeliverableItem::Manifesto->value]->state)
        ->toBe(LayerItemState::Skipped);
});

it('renders the checklist as markdown for the turn', function () {
    fillEntregable($this->client, DeliverableItem::Relato, 'Nace de una abuela.');

    $markdown = LayerProgress::for($this->client, BrandEggLayer::Esencia)->toMarkdown();

    expect($markdown)
        ->toContain('✅ Relato de marca')
        ->toContain('⬜ Brand promise')
        ->toContain('opcional');
});
