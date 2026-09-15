<?php

declare(strict_types=1);

use App\Enums\ClientStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Brand scoping for Breakfast staff.
 *
 * The Equipo role reaches only the brands it was put on; Admin reaches all of
 * them by role. The list screens narrow, but the enforcement is
 * EnsureStaffCoversClient — so every test here that matters types the URL.
 */

/** An Equipo member covering exactly the given brands. */
function equipoOn(Client ...$clients): User
{
    $user = User::factory()->equipo()->create();
    $user->assignedClients()->sync(collect($clients)->pluck('id'));

    return $user;
}

it('lets an equipo member open a brand they were put on', function () {
    $mine = Client::factory()->create();

    $this->actingAs(equipoOn($mine));

    $this->get(route('admin.clients.show', $mine))->assertOk();
});

it('refuses a brand they were not put on', function () {
    $mine = Client::factory()->create();
    $theirs = Client::factory()->create();

    $this->actingAs(equipoOn($mine));

    // 404, not 403: probing slugs must not reveal which brands exist.
    $this->get(route('admin.clients.show', $theirs))->assertNotFound();
});

it('guards every route that names a brand, not just the show page', function () {
    $theirs = Client::factory()->create();
    $this->actingAs(equipoOn(Client::factory()->create()));

    $this->get(route('admin.clients.process.edit', $theirs))->assertNotFound();
    $this->put(route('admin.clients.process.update', $theirs), [])->assertNotFound();
    $this->post(route('admin.clients.process.step', $theirs), [])->assertNotFound();
    $this->post(route('admin.clients.assets.store', $theirs), [])->assertNotFound();
    $this->post(route('admin.clients.users.store', $theirs), [])->assertNotFound();
    $this->post(route('admin.clients.process.assistant', $theirs), [])->assertNotFound();
});

it('cannot reach another brand by inviting a user into it', function () {
    Notification::fake();

    $theirs = Client::factory()->create();
    $this->actingAs(equipoOn(Client::factory()->create()));

    $this->post(route('admin.clients.users.store', $theirs), [
        'name' => 'Colado',
        'email' => 'colado@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertNotFound();

    expect(User::where('email', 'colado@lamarca.test')->exists())->toBeFalse();
});

it('shows an equipo member only their own brands in the list', function () {
    $mine = Client::factory()->create(['name' => 'Mia']);
    Client::factory()->create(['name' => 'Ajena']);

    $this->actingAs(equipoOn($mine));

    $this->get(route('admin.clients.index'))
        ->assertOk()
        ->assertSee('Mia')
        ->assertDontSee('Ajena');
});

it('counts only their own brands on the dashboard', function () {
    $mine = Client::factory()->create(['name' => 'Mia']);
    Client::factory()->create(['name' => 'Ajena']);
    Client::factory()->create(['name' => 'Tampoco']);

    $this->actingAs(equipoOn($mine));

    $this->get(route('admin.home'))
        ->assertOk()
        ->assertSee('Mia')
        ->assertDontSee('Ajena')
        ->assertDontSee('Tampoco');
});

it('shows an equipo member with no brands nothing at all', function () {
    $client = Client::factory()->create(['name' => 'Ajena']);

    $this->actingAs(equipoOn());

    // Fail closed. Zero assignments is a real answer, not "unrestricted".
    $this->get(route('admin.clients.index'))->assertOk()->assertDontSee('Ajena');
    $this->get(route('admin.clients.show', $client))->assertNotFound();
});

it('lets an admin reach every brand without being assigned to any', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['name' => 'Cualquiera']);

    expect($admin->assignedClients()->count())->toBe(0);

    $this->actingAs($admin);

    $this->get(route('admin.clients.show', $client))->assertOk();
    $this->get(route('admin.clients.index'))->assertOk()->assertSee('Cualquiera');
});

it('puts whoever creates a brand onto it', function () {
    // Otherwise an Equipo member is redirected straight into a 404 on the
    // brand they just made.
    $this->actingAs($member = equipoOn());

    $this->post(route('admin.clients.store'), [
        'name' => 'Marca Nueva',
        'status' => ClientStatus::Activo->value,
    ])->assertRedirect();

    $client = Client::firstWhere('name', 'Marca Nueva');

    expect($member->fresh()->covers($client))->toBeTrue();

    $this->get(route('admin.clients.show', $client))->assertOk();
});

it('keeps client users out regardless of any assignment', function () {
    $client = Client::factory()->create();
    $owner = User::factory()->clientOwner($client)->create();

    // Even with a row in the pivot — belt and braces, this should never exist.
    $owner->assignedClients()->sync([$client->id]);

    $this->actingAs($owner);

    // EnsureUserIsBreakfast refuses first; covers() would too.
    $this->get(route('admin.clients.show', $client))->assertNotFound();
    expect($owner->covers($client))->toBeFalse();
});
