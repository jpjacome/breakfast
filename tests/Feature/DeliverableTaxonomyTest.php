<?php

use App\Enums\DeliverableItem;
use App\Enums\ProcessStep;
use App\Models\Client;

/*
|--------------------------------------------------------------------------
| The taxonomy itself
|--------------------------------------------------------------------------
| These numbers come from docs/entregables.md, which extracts them from the
| team's PDF. If one of these fails after an edit to DeliverableItem, either
| the edit is wrong or the document moved — and if the document moved, it is
| the document that has to be updated in the same pass, not this test.
*/

test('there are 48 entregables, 19 required and 29 optional', function () {
    // Tipografia moved to optional on 2026-08-23 at Breakfast's request, which
    // is why this is 19/29 and not the 20/28 the deliverables PDF states.
    expect(DeliverableItem::cases())->toHaveCount(48)
        ->and(DeliverableItem::required())->toHaveCount(19)
        ->and(DeliverableItem::optional())->toHaveCount(29);
});

test('tipografia is optional, so an empty one is never announced as NO DEFINIDO', function () {
    // ERR-07. isRequired() decides nothing except how an absence reaches the
    // model — and an empty required entregable is printed as NO DEFINIDO, which
    // is what a client was shown as missing work.
    expect(DeliverableItem::Tipografia->isRequired())->toBeFalse();
});

test('the four that vary by tier are optional', function () {
    // Optional in tier B, required in C and D. Since tiers are not stored,
    // calling them required would lie to every brand on the smaller tier.
    expect(DeliverableItem::AnalisisDigital->isRequired())->toBeFalse()
        ->and(DeliverableItem::Manifesto->isRequired())->toBeFalse()
        ->and(DeliverableItem::Lineamientos->isRequired())->toBeFalse()
        ->and(DeliverableItem::Claim->isRequired())->toBeFalse();
});

test('every entregable has a label and a hint, and no two share a column', function () {
    $columns = DeliverableItem::columns();

    expect($columns)->toHaveCount(count(array_unique($columns)));

    foreach (DeliverableItem::cases() as $item) {
        expect($item->label())->not->toBe('')
            ->and($item->hint())->not->toBe('');
    }
});

test('the three steps chain and stop', function () {
    expect(ProcessStep::first())->toBe(ProcessStep::Arquitectura)
        ->and(ProcessStep::Arquitectura->next())->toBe(ProcessStep::Territorio)
        ->and(ProcessStep::Territorio->next())->toBe(ProcessStep::Toolkit)
        ->and(ProcessStep::Toolkit->next())->toBeNull()
        ->and(ProcessStep::Toolkit->isLast())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The row
|--------------------------------------------------------------------------
*/

test('a new brand gets its entregables row with the brand', function () {
    $client = Client::factory()->create();

    expect($client->deliverables)->not->toBeNull()
        ->and($client->deliverables->filledCount())->toBe(0)
        ->and($client->deliverables->completeness())->toBe(0);
});

test('a brand with no entregables reports 0 rather than crashing', function () {
    // deliverablesOrNew() covers rows that predate the created() hook.
    $client = Client::factory()->create();
    $client->deliverables()->delete();
    $client->unsetRelation('deliverables');

    expect($client->deliverablesOrNew()->completeness())->toBe(0)
        ->and($client->deliverablesOrNew()->missing())->toHaveCount(19);
});

test('content is written and read through the enum', function () {
    $client = Client::factory()->create();

    $client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Colores->value => 'Mostaza #ECBB12 — acento',
    ]);

    $deliverables = $client->deliverables->fresh();

    expect($deliverables->has(DeliverableItem::Relato))->toBeTrue()
        ->and($deliverables->value(DeliverableItem::Colores))->toBe('Mostaza #ECBB12 — acento')
        ->and($deliverables->has(DeliverableItem::Tono))->toBeFalse()
        ->and($deliverables->filledCount())->toBe(2);
});

test('whitespace is not content', function () {
    $client = Client::factory()->create();
    $client->deliverables->update([DeliverableItem::Tono->value => "   \n  "]);

    expect($client->deliverables->fresh()->has(DeliverableItem::Tono))->toBeFalse();
});

test('completeness counts only the required 20', function () {
    $client = Client::factory()->create();

    // Ten optionals do not move the number: a score that can never reach 100
    // is not a score.
    foreach (array_slice(DeliverableItem::optional(), 0, 10) as $item) {
        $client->deliverables->update([$item->value => 'algo']);
    }

    expect($client->deliverables->fresh()->completeness())->toBe(0);

    foreach (array_slice(DeliverableItem::required(), 0, 10) as $item) {
        $client->deliverables->update([$item->value => 'algo']);
    }

    // 10 of the 19 required. The denominator is the required ones only, so
    // the ten optionals above still count for nothing.
    expect($client->deliverables->fresh()->completeness())->toBe(53);
});

/*
|--------------------------------------------------------------------------
| What the assistant reads
|--------------------------------------------------------------------------
*/

test('an empty required entregable is stated as NO DEFINIDO', function () {
    $client = Client::factory()->create();
    $client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);

    $markdown = $client->deliverables->fresh()->toMarkdown();

    expect($markdown)
        ->toContain('Nació en Guadalajara.')
        // Silence gets completed by the model; an explicit absence does not.
        ->toContain('**Tono de comunicación**'."\n".'NO DEFINIDO');
});

test('empty optionals are collected into one closing line, not 28 of them', function () {
    $markdown = Client::factory()->create()->deliverables->toMarkdown();

    expect($markdown)
        ->toContain('No definido en este perfil')
        // The instruction is half the point of the line: the list is there so
        // the model does not invent, NOT so it can recite it to a client.
        // See ERR-07 of the beta review.
        ->toContain('no inventes estos datos')
        ->toContain('NO para contársela al cliente')
        ->and(substr_count($markdown, 'NO DEFINIDO'))->toBe(19);
});

test('a filled optional leaves the closing list', function () {
    $client = Client::factory()->create();
    $client->deliverables->update([DeliverableItem::Manifesto->value => 'Creemos que…']);

    $markdown = $client->deliverables->fresh()->toMarkdown();

    expect($markdown)->toContain('Creemos que…');

    [, $closing] = explode('No definido en este perfil', $markdown);

    expect($closing)->not->toContain('Manifesto');
});

test('the markdown is byte-stable for the same row', function () {
    // Block 2 of the prompt is cached on an exact prefix match with no
    // markers, so two reads of an unchanged row must serialise identically.
    $client = Client::factory()->create();
    $client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);

    expect($client->deliverables->fresh()->toMarkdown())
        ->toBe($client->deliverables->fresh()->toMarkdown());
});
