<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Models\Client;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The brand's own Brand Egg — read-only, and gated on approval.
 *
 * ⚠️ THIS IS THE ONE PLACE APPROVAL GATES ANYTHING. Everywhere else the state
 * changes what something SAYS: the assistant reads an unapproved Egg quite
 * happily and is told it is a draft. Here it decides whether the page exists,
 * because the Egg is the single artefact in this app whose whole claim is that
 * a person signed it off.
 */
function brandAndOwner(array $permissions): array
{
    $client = Client::factory()->create();

    // ⚠️ ->for($client) IS THE WHOLE MEMBERSHIP. UserFactory::configure()
    // syncs the brand_user pivot after creating, carrying the role and the
    // permissions map across — so attaching by hand here is a duplicate row,
    // which the pivot's unique index correctly refuses.
    $owner = User::factory()->clientOwner($client, $permissions)->create();

    return [$client->fresh(), $owner->fresh()];
}

/** An approved Egg with one composed layer. */
function approveEgg(Client $client): void
{
    $egg = $client->brandEgg()->make([
        BrandEggLayer::Esencia->value => 'Una marca de barrio que hace café como en casa.',
        'generated_at' => now(),
    ]);

    $egg->forceFill([
        'approved_at' => now(),
        'approved_by' => User::factory()->admin()->create()->id,
    ]);

    $client->brandEgg()->save($egg);
}

it('shows an approved egg to someone who may read Estrategia', function () {
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    approveEgg($client);

    actingAs($owner)->get(route('portal.estrategia.egg'))
        ->assertOk()
        ->assertSee('Una marca de barrio que hace café como en casa.');
});

it('opens while the egg is unapproved, and shows none of its words', function () {
    /*
     * ⚠️ THIS TEST ASSERTED A 404 UNTIL 2026-09-17, and the reversal is
     * Breakfast's call. Hiding the page meant a brand had no idea the Brand Egg
     * was part of what they were getting until the day it appeared.
     *
     * What approval gates has NOT moved: the composed TEXT of each layer. The
     * drawing and the five purposes are identical on every brand's egg, so they
     * describe the shape and say nothing about this brand — but a paragraph
     * nobody has signed off is exactly the unreviewed reading this app exists
     * to keep away from a client.
     */
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    $client->brandEgg()->save($client->brandEgg()->make([
        BrandEggLayer::Esencia->value => 'Un borrador sin aprobar.',
        'generated_at' => now(),
    ]));

    actingAs($owner)->get(route('portal.estrategia.egg'))
        ->assertOk()
        // The draft itself never reaches the brand.
        ->assertDontSee('Un borrador sin aprobar.', false)
        // The shape does, and so does what each layer is for.
        ->assertSee(BrandEggLayer::Esencia->description(), false)
        // ⚠️ And the empty state is not phrased as Breakfast's homework —
        // ERR-07. It is an egg, and it is still cooking.
        ->assertSee('se está cocinando', false)
        ->assertDontSee('pendiente', false)
        ->assertDontSee('aprobado', false);
});

it('offers the egg from Estrategia even before there is one', function () {
    // Same reason: the brand should know the thing exists.
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    actingAs($owner)->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee(route('portal.estrategia.egg'), false)
        ->assertSee('Todavía se está cocinando', false);
});

it('404s for someone who was not granted Estrategia at all', function () {
    [$client, $owner] = brandAndOwner([PortalSection::Reuniones->value => AccessLevel::Read->value]);

    approveEgg($client);

    actingAs($owner)->get(route('portal.estrategia.egg'))->assertNotFound();
});

it('keeps showing the egg after entregables move, rather than pulling it back', function () {
    // Desactualizado still counts as approved: somebody DID sign it off, and
    // the entregables having moved since is a reason for Breakfast to
    // recompose — not a reason to take the brand's memory away from it
    // mid-project.
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    approveEgg($client);

    $this->travel(1)->minutes();
    $client->deliverables->update([
        DeliverableItem::Relato->value => 'Reescrito después de aprobar.',
    ]);

    actingAs($owner)->get(route('portal.estrategia.egg'))->assertOk();
});

it('links the egg from Estrategia always, and says which state it is in', function () {
    /*
     * ⚠️ IT USED TO ASSERT THE LINK WAS ABSENT UNTIL APPROVAL, and Breakfast
     * reversed that on 2026-09-17: a brand had no idea the Brand Egg was part
     * of what they were getting until the day it appeared.
     *
     * The link is always there; the LINE UNDER IT changes. And neither version
     * reads as Breakfast being late - ERR-07. It is an egg, and before it is
     * ready it is cooking.
     */
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    actingAs($owner)->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Brand Egg')
        ->assertSee('se está cocinando', false);

    approveEgg($client);

    actingAs($owner)->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Brand Egg')
        ->assertDontSee('se está cocinando', false);
});

it('offers the client no way to write to their own egg', function () {
    // The client side writes in exactly three places and this is not one of
    // them. Breakfast writes a brand; the brand reads it.
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    approveEgg($client);

    actingAs($owner)->get(route('portal.estrategia.egg'))
        ->assertOk()
        ->assertDontSee('Componer')
        ->assertDontSee('Aprobar')
        ->assertDontSee('Editar a mano');
});
