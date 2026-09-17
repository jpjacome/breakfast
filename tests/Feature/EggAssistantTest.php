<?php

declare(strict_types=1);

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\BrandEggMessage;
use App\Models\Client;
use App\Models\User;
use App\Services\BrandEgg\EggComposer;
use App\Services\BrandEgg\LayerProgress;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/**
 * The conversation that co-creates a Brand Egg — step 1 of §1 of the brief.
 *
 * ⚠️ It is not a bigger EggComposer. That one reads entregables and writes a
 * paragraph; this one ASKS, which is the half a brand with no entregables
 * needs — and that is the normal case now that the Egg is built before the
 * toolkit.
 *
 * @see docs/brand-egg.md §14
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Panadería Sur']);

    config()->set('ai.providers.deepseek.api_key', 'sk-test');
});

function fakeEggTurn(string $reply = 'Empecemos por el Relato de marca.'): void
{
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => $reply], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
    ])]);
}

function askEgg(User $as, Client $client, string $message, ?BrandEggLayer $layer = BrandEggLayer::Esencia)
{
    return actingAs($as)->postJson(route('admin.clients.egg.assistant', $client), [
        'message' => $message,
        'layer' => $layer?->value,
    ]);
}

it('answers a turn and hands back the checklist with it', function () {
    fakeEggTurn();

    askEgg($this->admin, $this->client, '¿Por dónde empezamos?')
        ->assertOk()
        ->assertJsonPath('layer', 'esencia')
        // ⚠️ The checklist travels with the reply. Accepting a card moves a
        // tick, and the tick is derived from brand_deliverables — so the screen
        // is handed the new reading rather than guessing from what she said.
        ->assertJsonStructure(['reply', 'layer', 'checklist']);
});

it('puts the checklist in the request, rendered from the database', function () {
    fakeEggTurn();

    $this->client->deliverables()->firstOrNew()
        ->fill([DeliverableItem::Relato->value => 'Nace de una abuela.'])->save();

    askEgg($this->admin, $this->client, 'Seguimos');

    Http::assertSent(function ($request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, '✅ Relato de marca')
            && str_contains($sent, '⬜ Brand promise')
            // And which one is next, so she does not have to work it out.
            && str_contains($sent, 'El siguiente sin llenar es');
    });
});

it('keeps the layer and the brand OUT of the cached prefix', function () {
    /*
     * ⚠️ THE TEST THAT PROTECTS THE BILL. Five layers each with their own
     * system block is five cached prefixes instead of one — five paid readings
     * of the same instructions per brand. Same mistake the two-phase brandbook
     * read exists to avoid (CLAUDE.md §7).
     */
    fakeEggTurn();

    askEgg($this->admin, $this->client, 'Hola', BrandEggLayer::Personalidad);

    Http::assertSent(function ($request) {
        $system = collect($request->data()['messages'])
            ->firstWhere('role', 'system')['content'];

        return ! str_contains($system, 'Personalidad')
            && ! str_contains($system, 'Panadería Sur')
            && str_contains($system, 'TRES TIEMPOS');
    });
});

it('records what it asked, which is what makes no-aplica possible', function () {
    /*
     * ⚠️ NOT DECORATION. LayerProgress derives ➖ from an optional that is
     * empty AND has been asked about. Without this write, an optional the team
     * declined stays ⬜ forever and the layer can never finish — ERR-07 wearing
     * a checkbox.
     */
    fakeEggTurn('¿Hay un Manifesto? Si no lo hay, lo saltamos.');

    askEgg($this->admin, $this->client, '¿Qué falta?');

    $turn = BrandEggMessage::where('role', 'assistant')->sole();

    expect($turn->askedItems())->toContain(DeliverableItem::Manifesto);
});

it('only ever records entregables that feed the layer it is on', function () {
    // She is forbidden to raise anything else, and scanning all 48 would let a
    // passing mention mark an entregable as offered on a layer that never
    // reads it.
    fakeEggTurn('El tono de comunicación no lo vemos acá, pero el Relato de marca sí.');

    askEgg($this->admin, $this->client, 'Dale', BrandEggLayer::Esencia);

    $asked = BrandEggMessage::where('role', 'assistant')->sole()->askedItems();

    expect($asked)->toContain(DeliverableItem::Relato)
        ->and($asked)->not->toContain(DeliverableItem::Tono);
});

it('replays only this layer, not the whole Egg conversation', function () {
    BrandEggMessage::create([
        'client_id' => $this->client->id,
        'role' => 'user',
        'body' => 'Algo sobre el universo',
        'layer' => BrandEggLayer::Universo,
    ]);

    fakeEggTurn();
    askEgg($this->admin, $this->client, 'Ahora la yema', BrandEggLayer::Esencia);

    Http::assertSent(fn ($request) => ! str_contains(
        json_encode($request->data()),
        'Algo sobre el universo',
    ));
});

it('writes both halves of the turn, after the answer', function () {
    fakeEggTurn('Buena pregunta.');

    askEgg($this->admin, $this->client, '¿De dónde nace la marca?');

    expect(BrandEggMessage::count())->toBe(2)
        ->and(BrandEggMessage::where('role', 'user')->sole()->user_id)->toBe($this->admin->id)
        // The assistant half has no author: nobody typed it.
        ->and(BrandEggMessage::where('role', 'assistant')->sole()->user_id)->toBeNull();
});

it('stores nothing at all when the provider fails', function () {
    // A question stored with no answer replays as her ignoring somebody.
    Http::fake(['*' => Http::response('nope', 500)]);

    askEgg($this->admin, $this->client, '¿Hola?')->assertStatus(502);

    expect(BrandEggMessage::count())->toBe(0);
});

it('takes a turn about no layer at all', function () {
    // "¿Por dónde empezamos?" belongs to no ring. Forcing one would make her
    // pick a layer in order to say something general.
    fakeEggTurn();

    askEgg($this->admin, $this->client, '¿Por dónde empezamos?', null)
        ->assertOk()
        ->assertJsonPath('layer', null)
        ->assertJsonPath('checklist', null);
});

it('refuses a layer that is not one of the five', function () {
    fakeEggTurn();

    actingAs($this->admin)
        ->postJson(route('admin.clients.egg.assistant', $this->client), [
            'message' => 'Hola',
            'layer' => 'inventado',
        ])
        ->assertStatus(422);
});

it('answers validation as JSON, not as a redirect', function () {
    // Trap 13: a redirect here is a panel that spins forever.
    actingAs($this->admin)
        ->postJson(route('admin.clients.egg.assistant', $this->client), ['message' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['message']]);
});

it('keeps an equipo member out of a brand they do not cover', function () {
    fakeEggTurn();

    $outsider = User::factory()->equipo()->create();

    askEgg($outsider, $this->client, 'Hola')->assertNotFound();
});

/* -------------------------------------------------------------------------
 | The narrow entregable write
 ------------------------------------------------------------------------- */

it('saves one entregable without touching the other 47', function () {
    /*
     * ⚠️ THE WHOLE REASON THIS ROUTE EXISTS. The board's update() fills from
     * UpdateDeliverablesRequest::deliverables(), which returns ALL 48 because
     * it is a full save of a form. One entregable through that endpoint blanks
     * the other 47 — silent data loss, triggered by accepting a suggestion.
     */
    $this->client->deliverables()->firstOrNew()->fill([
        DeliverableItem::Valores->value => 'Oficio, barrio, paciencia.',
        DeliverableItem::Publicos->value => 'Vecinos del barrio.',
    ])->save();

    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [$this->client, BrandEggLayer::Esencia->value, DeliverableItem::Relato->value]), [
            'texto' => 'Nace de una abuela.',
        ])
        ->assertOk();

    $deliverables = $this->client->fresh()->deliverables;

    expect($deliverables->value(DeliverableItem::Relato))->toBe('Nace de una abuela.')
        ->and($deliverables->value(DeliverableItem::Valores))->toBe('Oficio, barrio, paciencia.')
        ->and($deliverables->value(DeliverableItem::Publicos))->toBe('Vecinos del barrio.');
});

it('moves the tick when an entregable is accepted', function () {
    expect(LayerProgress::for($this->client, BrandEggLayer::Esencia)->next())
        ->toBe(DeliverableItem::Relato);

    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [$this->client, BrandEggLayer::Esencia->value, DeliverableItem::Relato->value]), [
            'texto' => 'Nace de una abuela.',
        ]);

    expect(LayerProgress::for($this->client->fresh(), BrandEggLayer::Esencia)->next())
        ->toBe(DeliverableItem::BrandPromise);
});

it('404s on a key that is not one of the 48', function () {
    // ⚠️ The enum is the whitelist. A column name never comes from a request.
    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [$this->client, BrandEggLayer::Esencia->value, 'updated_by']), [
            'texto' => 'nope',
        ])
        ->assertNotFound();
});

it('keeps an equipo member out of the narrow write too', function () {
    actingAs(User::factory()->equipo()->create())
        ->patchJson(route('admin.clients.deliverable.update', [$this->client, BrandEggLayer::Esencia->value, DeliverableItem::Relato->value]), [
            'texto' => 'Nace de una abuela.',
        ])
        ->assertNotFound();
});

/* -------------------------------------------------------------------------
 | Volver a componer stops being lossy
 ------------------------------------------------------------------------- */

it('composes a layer from the conversation when no entregable holds it', function () {
    /*
     * ⚠️ THE BUTTON THIS FIXES. EggComposer read sources() and nothing else, so
     * a layer built out of a conversation — tú/usted, what the brand would
     * never say — could be silently overwritten by a paragraph that knows none
     * of it. The layer got quietly worse and the button that did it looked like
     * a refresh.
     */
    BrandEggMessage::create([
        'client_id' => $this->client->id,
        'role' => 'user',
        'body' => 'Trata de tú, se ríe de sí misma, y jamás usaría jerga corporativa.',
        'layer' => BrandEggLayer::Personalidad,
    ]);

    fakeEggTurn('La marca habla de tú.');

    $text = app(EggComposer::class)
        ->compose($this->client->fresh(), BrandEggLayer::Personalidad);

    expect($text)->not->toBeNull();

    Http::assertSent(function ($request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'jerga corporativa')
            // Worth the same as an entregable, not framed as background.
            && str_contains($sent, 'Vale igual que un entregable');
    });
});

it('still refuses a layer with no entregables and no conversation', function () {
    // An empty layer is honest; a layer of hedging is noise (§2 Finding 3).
    expect(app(EggComposer::class)
        ->compose($this->client, BrandEggLayer::Personalidad))
        ->toBeNull();
});

it('refuses an entregable that the section does not read', function () {
    /*
     * ⚠️ BREAKFAST'S RULE, MADE A FACT. This assistant may only write the
     * entregables ATTACHED TO THE SECTION being filled. The prompt says so too
     * — and a prompt is a request. A loosened prompt, a confused turn or a
     * hand-made request still cannot land a card on an entregable this section
     * does not read.
     *
     * Publicos feeds layer 3, never layer 1.
     */
    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [
            $this->client, BrandEggLayer::Esencia->value, DeliverableItem::Publicos->value,
        ]), ['texto' => 'Vecinos del barrio.'])
        ->assertNotFound();

    expect($this->client->fresh()->deliverables?->value(DeliverableItem::Publicos) ?? '')->toBe('');

    // And the same entregable through its OWN section is fine.
    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [
            $this->client, BrandEggLayer::Beneficios->value, DeliverableItem::Publicos->value,
        ]), ['texto' => 'Vecinos del barrio.'])
        ->assertOk();
});

it('hands the new checklist back when a card is accepted', function () {
    // The card moved a tick and the tick is derived, so the panel is handed the
    // new reading rather than inferring one.
    actingAs($this->admin)
        ->patchJson(route('admin.clients.deliverable.update', [
            $this->client, BrandEggLayer::Esencia->value, DeliverableItem::Relato->value,
        ]), ['texto' => 'Nace de una abuela.'])
        ->assertOk()
        ->assertJsonPath('saved', 'relato');
});

it('tells Brandy how to wrap a proposal, in ASCII', function () {
    /*
     * ⚠️ A CONTRACT WITH egg-assistant.js. Anything inside the marker becomes a
     * card; anything outside is prose. The asymmetry is the design: a marker
     * she forgets degrades into a readable sentence, where a malformed JSON
     * field would have broken the turn.
     */
    $prompt = config('ai.egg_assistant_prompt');

    expect($prompt)
        ->toContain('⟦guardar seccion=esencia item=relato⟧')
        ->toContain('⟦/guardar⟧')
        // ASCII keys, so an accent cannot break the parse.
        ->not->toContain('sección=')
        // And the rule that keeps her inside the section.
        ->toContain('Nunca nombras un entregable que no alimenta esta sección');
});

it('renders the panel on the admin egg screen', function () {
    actingAs($this->admin)
        ->get(route('admin.clients.egg.edit', $this->client))
        ->assertOk()
        ->assertSee('data-egg-assistant', false)
        // The ring picker decides where a card is allowed to save, so it has to
        // be on screen rather than behind a dropdown.
        ->assertSee('data-egg-layer', false)
        // And the save endpoint carries both placeholders the script fills in.
        ->assertSee('__LAYER__', false)
        ->assertSee('__ITEM__', false);
});

it('gives the client the purpose of each layer, not just its text', function () {
    $client = Client::factory()->create();

    $client->brandEgg()->create([
        BrandEggLayer::Esencia->value => 'Una panadería de barrio.',
        'generated_at' => now(),
    ])->forceFill(['approved_at' => now(), 'approved_by' => $this->admin->id])->save();

    $owner = User::factory()->clientOwner($client->id, ['estrategia' => 'read'])->create();

    actingAs($owner)
        ->get(route('portal.estrategia.egg'))
        ->assertOk()
        ->assertSee('Una panadería de barrio.', false)
        // Straight from the enum, so the client's reading of a layer and
        // Breakfast's are the same words.
        ->assertSee(BrandEggLayer::Esencia->description(), false);
});
