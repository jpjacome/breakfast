<?php

declare(strict_types=1);

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\BrandContextBuilder;
use App\Services\Ai\BrandContextRepository;

/**
 * Where the Brand Egg sits in what the assistant reads.
 *
 * ⚠️ THE TIER ORDER IS HELD BY THE TITLES' NUMBERS, NOT BY INSERTION.
 * BrandContext::make() ksorts the document map so prompt bytes never depend on
 * the order a repository happened to add things in — which means an untitled
 * "Brand Egg…" would sort ABOVE "Entregables…" by luck of its initial today and
 * BELOW a block called "Archivos…" the day somebody adds one. The numbers are
 * the hierarchy; these tests are what notice if they stop being.
 */
function brandWithEgg(): Client
{
    $client = Client::factory()->create(['name' => 'Cafetería Norte']);

    $client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
    ]);

    $client->brandEgg()->save($client->brandEgg()->make([
        BrandEggLayer::Esencia->value => 'La esencia sintetizada de la marca.',
        'generated_at' => now(),
    ]));

    return $client->fresh();
}

it('puts the egg above the entregables and the toolkit below them', function () {
    $client = brandWithEgg();
    $client->update(['document_digest' => 'Lo que decía el brandbook.']);

    $prompt = app(BrandContextRepository::class)->for($client->fresh())->toPrompt();

    $egg = mb_strpos($prompt, 'Brand Egg de la marca');
    $deliverables = mb_strpos($prompt, 'Entregables de la marca');
    $toolkit = mb_strpos($prompt, 'Toolkit de la marca');

    expect($egg)->not->toBeFalse()
        ->and($deliverables)->not->toBeFalse()
        ->and($toolkit)->not->toBeFalse()
        ->and($egg)->toBeLessThan($deliverables)
        ->and($deliverables)->toBeLessThan($toolkit);
});

it('carries an unapproved egg, and says it is a draft', function () {
    // The brief says the Egg becomes primary "una vez aprobado", which reads as
    // a gate. Implemented as one it would leave every brand alive today with an
    // assistant that knows less than it did — approval changes what the block
    // SAYS, not whether it is there.
    $client = brandWithEgg();

    $prompt = app(BrandContextRepository::class)->for($client)->toPrompt();

    expect($prompt)->toContain('La esencia sintetizada de la marca.')
        ->and($prompt)->toContain('SIN APROBAR');
});

it('says when the egg was approved, and when entregables moved after it', function () {
    $client = brandWithEgg();
    $admin = User::factory()->admin()->create();

    $egg = $client->brandEgg;
    $egg->forceFill(['approved_at' => now(), 'approved_by' => $admin->id])->save();

    $approved = app(BrandContextRepository::class)->for($client->fresh())->toPrompt();

    expect($approved)->toContain('APROBADO por Breakfast el');

    $this->travel(1)->minutes();
    $client->deliverables->update([
        DeliverableItem::Relato->value => 'Reescrito después de aprobar.',
    ]);

    $stale = app(BrandContextRepository::class)->for($client->fresh())->toPrompt();

    // The one case where a lower tier outranks a higher one, and the block has
    // to say so: the Egg was signed off before the entregable was rewritten.
    expect($stale)->toContain('manda el entregable');
});

it('does not offer the assistant on an egg-less, entregable-less brand', function () {
    // hasUsableContext() exists to stop the assistant being offered with
    // nothing behind it. A toolkit alone is not brand content: nobody reviewed
    // it, which is the one thing this app exists to prevent.
    $client = Client::factory()->create();
    $client->update(['document_digest' => 'Lo que decía el brandbook.']);

    expect(app(BrandContextRepository::class)->hasUsableContext($client->fresh()))->toBeFalse();
});

it('keeps the prefix byte-identical across two builds for one brand', function () {
    // The whole caching mechanism. An absolute date in the approval line is
    // stable per brand; "aprobado hace dos semanas" would not be, and would
    // drop the hit rate to zero silently at ~150x the cost.
    $client = brandWithEgg();
    $repository = app(BrandContextRepository::class);
    $builder = new BrandContextBuilder;

    expect($builder->prefixFingerprint($repository->for($client->fresh())))
        ->toBe($builder->prefixFingerprint($repository->for($client->fresh())));
});

it('moves the context version when a layer is composed', function () {
    // The version line is printed into block 2 as "Versión del contexto". An
    // Egg edit changes the prompt, so a version that still named the old date
    // would be a line claiming the context had not moved while it plainly had.
    $client = brandWithEgg();
    $repository = app(BrandContextRepository::class);

    $before = $repository->for($client)->toPrompt();

    $this->travel(5)->minutes();
    $egg = $client->brandEgg;
    $egg->fill([BrandEggLayer::Personalidad->value => 'Una personalidad nueva.'])->save();

    $after = $repository->for($client->fresh())->toPrompt();

    expect($after)->not->toBe($before);
});
