<?php

use App\Actions\StartBrandDraft;
use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Enums\ProcessStep;
use App\Models\Client;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);
});

/*
|--------------------------------------------------------------------------
| Reaching the screen
|--------------------------------------------------------------------------
*/

test('the screen opens for a brand that has never been worked on', function () {
    actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        ->assertSee('Proceso y entregables')
        ->assertSee('Identidad de marca')
        ->assertSee('Sin empezar');
});

test('all 48 entregables are on the page', function () {
    $response = actingAs($this->admin)->get(route('admin.clients.process.edit', $this->client));

    foreach (DeliverableItem::cases() as $item) {
        $response->assertSee("entregables[{$item->value}]", escape: false);
    }
});

test('client users never reach it', function () {
    // 404 rather than 403 on purpose — EnsureUserIsBreakfast hides whether the
    // admin area exists at all.
    actingAs(User::factory()->clientOwner()->create())
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertNotFound();
});

test('equipo only reaches the brands they are on', function () {
    $staff = User::factory()->equipo()->create();

    actingAs($staff)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertNotFound();

    $this->client->staff()->attach($staff);

    actingAs($staff)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Saving entregables
|--------------------------------------------------------------------------
*/

test('entregables are saved and read back', function () {
    actingAs($this->admin)
        ->put(route('admin.clients.process.update', $this->client), [
            'entregables' => [
                DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
                DeliverableItem::Colores->value => 'Mostaza #ECBB12 — acento',
            ],
        ])
        ->assertRedirect(route('admin.clients.process.edit', $this->client));

    $deliverables = $this->client->deliverables->fresh();

    expect($deliverables->value(DeliverableItem::Relato))->toBe('Nació en una cocina de Guadalajara.')
        ->and($deliverables->filledCount())->toBe(2)
        ->and($deliverables->editor->is($this->admin))->toBeTrue();
});

test('an entregable can be cleared', function () {
    $this->client->deliverables->update([DeliverableItem::Tono->value => 'Directo.']);

    // The save is wholesale: an entregable the form does not carry is one
    // somebody emptied. Merging would make clearing impossible.
    actingAs($this->admin)->put(route('admin.clients.process.update', $this->client), [
        'entregables' => [DeliverableItem::Relato->value => 'Otra cosa.'],
    ]);

    expect($this->client->deliverables->fresh()->has(DeliverableItem::Tono))->toBeFalse();
});

test('whitespace is stored as empty, not as content', function () {
    actingAs($this->admin)->put(route('admin.clients.process.update', $this->client), [
        'entregables' => [DeliverableItem::Tono->value => "   \n "],
    ]);

    expect($this->client->deliverables->fresh()->has(DeliverableItem::Tono))->toBeFalse();
});

test('a key that is not an entregable is ignored', function () {
    actingAs($this->admin)->put(route('admin.clients.process.update', $this->client), [
        'entregables' => [
            'no_existe' => 'algo',
            DeliverableItem::Relato->value => 'Sí existe.',
        ],
    ])->assertRedirect();

    expect($this->client->deliverables->fresh()->filledCount())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Moving the steps
|--------------------------------------------------------------------------
*/

test('starting and completing a step walks to the next one', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'iniciar',
    ])->assertRedirect(route('admin.clients.process.edit', $this->client));

    expect($this->client->fresh()->currentStep())->toBe(ProcessStep::Arquitectura);

    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'completar',
    ]);

    expect($this->client->fresh()->currentStep())->toBe(ProcessStep::Territorio);
});

test('a step closes with nothing filled in', function () {
    // The entregables never gate a step: the admin knows when a step ended.
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Toolkit->value,
        'action' => 'completar',
    ])->assertRedirect();

    expect($this->client->fresh()->completedStepCount())->toBe(1)
        ->and($this->client->deliverables->filledCount())->toBe(0);
});

test('a step that does not exist is refused rather than guessed at', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => 'inventado',
        'action' => 'iniciar',
    ]);

    expect($this->client->fresh()->processSteps)->toHaveCount(0);
});

test('an unknown action is rejected by validation', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'borrar',
    ])->assertSessionHasErrors('action');
});

test('the brand page links into the process and reports where it is', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'iniciar',
    ]);

    actingAs($this->admin)
        ->get(route('admin.clients.show', $this->client))
        ->assertOk()
        ->assertSee(route('admin.clients.process.edit', $this->client))
        ->assertSee('Paso 1 de 3');
});

/*
|--------------------------------------------------------------------------
| Drafts on /clientes/nueva
|--------------------------------------------------------------------------
*/

test('the autosave keeps the board, not just the brand fields', function () {
    // The bug this pins: the first version saved only the five brand fields,
    // so approving twenty-three proposals and closing the tab lost every one
    // while the screen said the page saved itself.
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'name' => 'The Roastery',
        'entregables' => [
            DeliverableItem::Relato->value => 'Nació en una cocina.',
            DeliverableItem::Colores->value => 'Mostaza #ECBB12',
        ],
    ])->assertOk()->assertJson(['saved' => true]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    expect($draft->deliverables->value(DeliverableItem::Relato))->toBe('Nació en una cocina.')
        ->and($draft->deliverables->filledCount())->toBe(2);
});

test('the save that creates the draft keeps the whole form, not just the name', function () {
    // The bug this pins: the creating save wrote name + status and returned,
    // so the FIRST autosave — the only one that ever sees a screen filled in
    // before anything was stored — dropped the industry, the contact and the
    // notes. The page was told "Borrador guardado" and marks that snapshot as
    // saved, so it never posted them again: typed, acknowledged, and gone.
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'name' => 'The Roastery',
        'industry' => 'Café de especialidad',
        'contact_name' => 'María Peña',
        'contact_email' => 'maria@theroastery.com',
        'notes' => 'Llegaron por recomendación.',
    ])->assertOk()->assertJson(['saved' => true]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    expect($draft->industry)->toBe('Café de especialidad')
        ->and($draft->contact_name)->toBe('María Peña')
        ->and($draft->contact_email)->toBe('maria@theroastery.com')
        ->and($draft->notes)->toBe('Llegaron por recomendación.');
});

test('a board with no brand fields still creates the draft and keeps them blank', function () {
    // The other half: an entregable is content on its own, and the empty boxes
    // beside it must not be written as values.
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'name' => '',
        'industry' => '',
        'entregables' => [DeliverableItem::Relato->value => 'Nació en una cocina.'],
    ])->assertOk()->assertJson(['saved' => true]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    expect($draft->name)->toBe(StartBrandDraft::PLACEHOLDER_NAME)
        ->and($draft->industry)->toBeNull()
        ->and($draft->deliverables->value(DeliverableItem::Relato))->toBe('Nació en una cocina.');
});

test('a save that carries no board leaves the stored one alone', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'name' => 'The Roastery',
        'entregables' => [DeliverableItem::Relato->value => 'Lo escrito.'],
    ])->assertOk();

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    // Null and empty are different: a save with no board must not wipe it.
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'draft' => $draft->slug,
        'name' => 'The Roastery',
        'industry' => 'Café',
    ])->assertOk();

    expect($draft->deliverables->fresh()->value(DeliverableItem::Relato))->toBe('Lo escrito.');
});

test('nothing is created until there is something to keep', function () {
    actingAs($this->admin)
        ->postJson(route('admin.clients.draft.save'), ['name' => ''])
        ->assertOk()
        ->assertJson(['saved' => false, 'draft' => null]);

    expect(Client::where('status', ClientStatus::Borrador)->count())->toBe(0);
});

test('a draft is listed and links back to where it was being written', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'entregables' => [DeliverableItem::Relato->value => 'Algo.'],
    ]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    actingAs($this->admin)
        ->get(route('admin.clients.index'))
        ->assertOk()
        ->assertSee($draft->name)
        ->assertSee('Sin terminar')
        // Not the brand page: a draft has no process, team or files yet.
        ->assertSee(route('admin.clients.create', ['borrador' => $draft->slug]), escape: false);
});

test('finishing a draft keeps its board and stops it being a draft', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'name' => 'The Roastery',
        'entregables' => [DeliverableItem::Relato->value => 'Nació en una cocina.'],
    ]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    actingAs($this->admin)->post(route('admin.clients.store'), [
        'draft' => $draft->slug,
        'name' => 'The Roastery',
        'status' => 'activo',
        'entregables' => [DeliverableItem::Relato->value => 'Nació en una cocina.'],
    ])->assertRedirect(route('admin.clients.process.edit', $draft));

    $client = $draft->fresh();

    expect($client->status->isDraft())->toBeFalse()
        ->and($client->deliverables->value(DeliverableItem::Relato))->toBe('Nació en una cocina.')
        // One row throughout: finishing must not fork the brand in two.
        ->and(Client::count())->toBe(2);
});

test('a draft belonging to a brand you cannot see is not resumable', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'entregables' => [DeliverableItem::Relato->value => 'Algo.'],
    ]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();
    $staff = User::factory()->equipo()->create();

    // Hand-posting the slug must not let an unassigned member write to it.
    actingAs($staff)->postJson(route('admin.clients.draft.save'), [
        'draft' => $draft->slug,
        'name' => 'Secuestrada',
    ])->assertOk();

    expect($draft->fresh()->name)->not->toBe('Secuestrada');
});

/*
|--------------------------------------------------------------------------
| Only one step running at a time
|--------------------------------------------------------------------------
| Regression, found live on 2026-08-14: a brand called Passiflor had both
| Arquitectura and Territorio "running" at once, because "Iniciar paso" is a
| button on every not-yet-started card independently. currentStep() picks the
| oldest running row, so the client's dashboard froze on "Paso 1 de 3" while
| the admin's own step cards — reading each step on its own — correctly showed
| two lit up. See AdvanceProcessStep.
*/

test('starting a step while another is already running is refused', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'iniciar',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.step', $this->client), [
            'step' => ProcessStep::Territorio->value,
            'action' => 'iniciar',
        ])
        ->assertRedirect(route('admin.clients.process.edit', $this->client))
        ->assertSessionHas('status', fn ($status) => str_contains($status, 'Identidad de marca')
            && str_contains($status, 'en curso'));

    // The whole point: no second running row, and the client-facing bar keeps
    // reading the step that is actually current.
    expect($this->client->fresh()->currentStep())->toBe(ProcessStep::Arquitectura)
        ->and($this->client->processSteps)->toHaveCount(1);
});

test('starting the same step twice is still a no-op, not a refusal', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'iniciar',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.step', $this->client), [
            'step' => ProcessStep::Arquitectura->value,
            'action' => 'iniciar',
        ])
        ->assertSessionHas('status', 'Paso 1 iniciado: Identidad de marca.');
});

test('completing a step and reopening it still works with the next one running', function () {
    // The one place two steps ARE allowed to read as running at once: undoing
    // a misclick has to work even though completing the step already started
    // the next one automatically.
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'completar',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.process.step', $this->client), [
            'step' => ProcessStep::Arquitectura->value,
            'action' => 'reabrir',
        ])
        ->assertSessionHas('status', 'Paso 1 reabierto: Identidad de marca.');

    expect($this->client->fresh()->stepRecord(ProcessStep::Arquitectura)->isRunning())->toBeTrue();
});

test('the process screen disables Iniciar paso on the other cards while one runs', function () {
    actingAs($this->admin)->post(route('admin.clients.process.step', $this->client), [
        'step' => ProcessStep::Arquitectura->value,
        'action' => 'iniciar',
    ]);

    actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        // An admin who clicked it anyway would only get the same sentence
        // back as a flash message, one request later.
        ->assertSee('Cierra «Identidad de marca» primero.');
});
