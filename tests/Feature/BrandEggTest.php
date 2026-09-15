<?php

declare(strict_types=1);

use App\Enums\BrandEggLayer;
use App\Enums\BrandEggState;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * The Brand Egg screen and its three writes.
 *
 * The composer itself is pinned in tests/Feature/Ai/EggComposerTest.php. What
 * is here is the rest: who may reach these routes, what the four states are
 * derived from, and the two ways text gets into a layer that are not a
 * composition.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

/** An Equipo member covering exactly the given brands. */
function eggEquipoOn(Client ...$clients): User
{
    $user = User::factory()->equipo()->create();
    $user->assignedClients()->sync(collect($clients)->pluck('id'));

    return $user;
}

/* --- the screen ---------------------------------------------------------- */

it('opens for an admin, showing every layer and the egg state', function () {
    $this->actingAs($this->admin);

    $this->get(route('admin.clients.egg.edit', $this->client))
        ->assertOk()
        ->assertSee('Esencia, tagline y valores')
        ->assertSee('Brand Universe / Emotions')
        ->assertSee('Sin generar');
});

it('404s for an equipo member who was not put on the brand', function () {
    // 404, not 403, on every one of the four — covers-client fires the moment a
    // route carries {client}, so probing slugs cannot reveal which brands exist.
    $this->actingAs(eggEquipoOn(Client::factory()->create()));

    $this->get(route('admin.clients.egg.edit', $this->client))->assertNotFound();
    $this->post(route('admin.clients.egg.compose', $this->client))->assertNotFound();
    $this->post(route('admin.clients.egg.approve', $this->client))->assertNotFound();
    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'x'])
        ->assertNotFound();
});

it('keeps a client user out entirely', function () {
    $user = User::factory()->create();
    $user->brands()->attach($this->client->id, ['role' => 'owner', 'permissions' => '{}']);

    $this->actingAs($user);

    $this->get(route('admin.clients.egg.edit', $this->client))->assertNotFound();
});

it('404s a layer segment that is not one of the five', function () {
    // The enum binds in the route, so a bad value never reaches the controller
    // and there is no match with a default branch to get wrong downstream.
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'ok'])
        ->assertRedirect();

    $this->put('/admin/clientes/'.$this->client->slug.'/brand-egg/inventada', ['text' => 'ok'])
        ->assertNotFound();
});

/* --- writing a layer by hand --------------------------------------------- */

it('accepts a layer typed by a person, and counts it as generated', function () {
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), [
        'text' => 'Una marca de barrio que hace café como en casa.',
    ])->assertRedirect();

    $client = $this->client->fresh();

    expect($client->brandEgg->text(BrandEggLayer::Esencia))
        ->toBe('Una marca de barrio que hace café como en casa.')
        // Nothing was composed, but something IS in the egg — "sin generar"
        // would be a lie and the state would skip past "sin aprobar".
        ->and($client->brandEggState())->toBe(BrandEggState::SinAprobar);
});

it('empties a layer when a blank is saved, rather than refusing it', function () {
    // A real instruction: it is how a ring goes back to hollow when its
    // synthesis was wrong and its sources are not ready to be read again.
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'Algo.']);
    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => '']);

    expect($this->client->fresh()->brandEgg->has(BrandEggLayer::Esencia))->toBeFalse();
});

/* --- approval, and the four states --------------------------------------- */

it('refuses to approve an empty egg', function () {
    // Fail closed rather than stamping an approval onto nothing: an approved
    // empty egg would show the client a blank drawing and claim a person had
    // signed it off.
    $this->actingAs($this->admin);

    $this->post(route('admin.clients.egg.approve', $this->client))->assertRedirect();

    expect($this->client->fresh()->brandEggOrNew()->approved_at)->toBeNull()
        ->and($this->client->fresh()->brandEggState())->toBe(BrandEggState::SinGenerar);
});

it('approves, recording who and when', function () {
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'La esencia.']);
    $this->post(route('admin.clients.egg.approve', $this->client))->assertRedirect();

    $egg = $this->client->fresh()->brandEgg;

    expect($egg->approved_at)->not->toBeNull()
        ->and($egg->approver->is($this->admin))->toBeTrue()
        ->and($this->client->fresh()->brandEggState())->toBe(BrandEggState::Aprobado);
});

it('flips aprobado to desactualizado when an entregable moves, with no column written', function () {
    // The state that is never stored. A flag would have to be flipped by every
    // screen that writes an entregable, forever, so it would be wrong the first
    // time somebody added a route.
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'La esencia.']);
    $this->post(route('admin.clients.egg.approve', $this->client));

    expect($this->client->fresh()->brandEggState())->toBe(BrandEggState::Aprobado);

    $before = $this->client->fresh()->brandEgg->getAttributes();

    $this->travel(1)->minutes();
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Reescrito después de aprobar.',
    ]);

    expect($this->client->fresh()->brandEggState())->toBe(BrandEggState::Desactualizado)
        // Nothing on the egg itself moved. The state is a comparison.
        ->and($this->client->fresh()->brandEgg->getAttributes())->toBe($before);
});

it('re-stamps on a second approval rather than erroring', function () {
    // What somebody means when they approve a brand they have just edited.
    // There is deliberately no unapprove.
    $this->actingAs($this->admin);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'La esencia.']);
    $this->post(route('admin.clients.egg.approve', $this->client));

    $first = $this->client->fresh()->brandEgg->approved_at;

    $this->travel(2)->minutes();
    $this->post(route('admin.clients.egg.approve', $this->client))->assertRedirect();

    expect($this->client->fresh()->brandEgg->approved_at->greaterThan($first))->toBeTrue();
});

it('does not un-approve an egg when one ring is recomposed', function () {
    // Clearing the stamp would throw away the record of who signed it off and
    // when. What shows instead is Desactualizado, derived from the entregables.
    $this->actingAs($this->admin);

    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina.',
    ]);

    $this->put(route('admin.clients.egg.update', [$this->client, 'esencia']), ['text' => 'La esencia.']);
    $this->post(route('admin.clients.egg.approve', $this->client));

    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => 'Una esencia nueva.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 90],
    ])]);

    $this->post(route('admin.clients.egg.compose', $this->client), ['layer' => 'esencia'])
        ->assertOk();

    expect($this->client->fresh()->brandEgg->approved_at)->not->toBeNull();
});

/* --- composing, over the wire -------------------------------------------- */

it('composes one layer and answers with the egg and its new state', function () {
    $this->actingAs($this->admin);

    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
    ]);

    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-v4-pro',
        'choices' => [['message' => ['content' => 'La esencia sintetizada.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 90],
    ])]);

    $this->post(route('admin.clients.egg.compose', $this->client), ['layer' => 'esencia'])
        ->assertOk()
        ->assertJson([
            'composed' => ['esencia'],
            'state' => 'sin_aprobar',
            'egg' => ['esencia' => 'La esencia sintetizada.'],
        ]);
});

it('reports a layer with no sources as skipped rather than as a failure', function () {
    // Not one of the entregables it reads has been written. That is a fact
    // about where the brand is, not something that went wrong — and no call is
    // made, which Http::preventStrayRequests() would catch if one were.
    $this->actingAs($this->admin);

    $this->post(route('admin.clients.egg.compose', $this->client), ['layer' => 'esencia'])
        ->assertOk()
        ->assertJson(['composed' => [], 'skipped' => ['esencia']]);
});

it('answers a bad layer with JSON, not a redirect', function () {
    // CLAUDE.md trap 13. Composing is driven by fetch(), and a redirect to a
    // fetch() reads on screen as a silent failure — three "No obtuve
    // respuesta." in front of a client, in the case that named the trap.
    $this->actingAs($this->admin);

    $response = $this->postJson(
        route('admin.clients.egg.compose', $this->client),
        ['layer' => 'no-es-una-capa'],
    );

    $response->assertStatus(422);
    expect($response->headers->get('content-type'))->toContain('application/json');
});

it('answers a provider failure with the layers that did land', function () {
    // A run that dies partway has already saved what it composed. Throwing the
    // response away would leave the screen disagreeing with the database until
    // somebody reloaded.
    $this->actingAs($this->admin);

    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina.',
    ]);

    $this->put(route('admin.clients.egg.update', [$this->client, 'personalidad']), [
        'text' => 'Una personalidad ya escrita.',
    ]);

    Http::fake(['api.deepseek.com/*' => Http::response('', 503)]);

    $this->post(route('admin.clients.egg.compose', $this->client), ['layer' => 'esencia'])
        ->assertStatus(502)
        ->assertJson(['egg' => ['personalidad' => 'Una personalidad ya escrita.']]);
});

it('carries both gates on the compose route', function () {
    // A rate limit counts requests per minute and cannot bound how many run at
    // once, which is the thing that takes the public site down. CLAUDE.md trap 5.
    $middleware = collect(app('router')->getRoutes()->getByName('admin.clients.egg.compose')
        ->gatherMiddleware());

    expect($middleware)->toContain('throttle:10,1')
        ->and($middleware)->toContain('ai-turn');
});
