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

it('404s while the egg is unapproved', function () {
    // ⚠️ 404, NOT A MESSAGE. A member should not learn there is a draft of
    // their brand's essence they are not being shown — "todavía no está
    // aprobado" is a sentence about Breakfast's internal work, said to the
    // wrong audience.
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    $client->brandEgg()->save($client->brandEgg()->make([
        BrandEggLayer::Esencia->value => 'Un borrador sin aprobar.',
        'generated_at' => now(),
    ]));

    actingAs($owner)->get(route('portal.estrategia.egg'))->assertNotFound();
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

it('links the egg from Estrategia only once it is approved', function () {
    [$client, $owner] = brandAndOwner([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    actingAs($owner)->get(route('portal.estrategia'))->assertDontSee('Brand Egg');

    approveEgg($client);

    actingAs($owner)->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Brand Egg');
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
