<?php

use App\Actions\AdvanceProcessStep;
use App\Enums\AccessLevel;
use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Enums\ProcessStep;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\BrandContextRepository;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);
    $this->owner = User::factory()->clientOwner($this->client->id, [
        PortalSection::Estrategia->value => AccessLevel::Read->value,
        PortalSection::Reuniones->value => AccessLevel::Read->value,
    ])->create();
    $this->steps = app(AdvanceProcessStep::class);
});

/*
|--------------------------------------------------------------------------
| The three steps, as the client sees them
|--------------------------------------------------------------------------
*/

test('a brand that has not started says so rather than showing an empty bar', function () {
    // The bar lives on the dashboard now — /portal/proyecto, the page it used
    // to have to itself, was removed with the sidebar cleanup.
    actingAs($this->owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Todavía no empezamos');
});

test('the bar names the running step and its date', function () {
    $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->steps->complete($this->client, ProcessStep::Arquitectura, User::factory()->admin()->create());

    actingAs($this->owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Paso 2 de 3')
        ->assertSee('Territorio')
        ->assertSee('Identidad de marca');

    // ⚠️ No date here: the dashboard renders the COMPACT bar, and the dated
    // form was on /portal/proyecto, which no longer exists. The full bar is
    // still in x-portal.step-bar and nothing sets compact=false any more.
});

/**
 * Regression, reported by a tester on 2026-08-14: a brand called Passiflor had
 * both Arquitectura and Territorio "running" at once, because the admin
 * process screen let "Iniciar paso" be clicked on any not-started card
 * regardless of what else was open. Client::currentStep() picked the OLDER of
 * the two, so the dashboard froze on "Paso 1 de 3" even after the admin
 * genuinely marked Arquitectura complete — completing it does not change
 * which row started first among what is left running. AdvanceProcessStep::
 * start() now refuses to create the second row in the first place; this pins
 * that the client-facing header recovers correctly once it does.
 */
test('the header matches reality after recovering from two steps running at once', function () {
    $admin = User::factory()->admin()->create();

    // The exact starting shape Passiflor had: two independent starts, the
    // second one now a no-op rather than a second running row.
    $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->steps->start($this->client, ProcessStep::Territorio);

    $this->steps->complete($this->client, ProcessStep::Arquitectura, $admin);

    actingAs($this->owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Paso 2 de 3')
        ->assertDontSee('Paso 1 de 3');
});

test('every person in the brand sees the step bar on their dashboard', function () {
    $this->steps->start($this->client, ProcessStep::Arquitectura);

    // It used to be gated on Proyecto, which was the page it belonged to.
    // That section is gone, and the dashboard is every brand person's own
    // screen: where their brand is up to is the least private thing on it.
    $member = User::factory()->clientMember($this->client->id, [])->create();

    actingAs($member)->get(route('portal.home'))->assertOk()->assertSee('El proceso');

    // A section they were never granted is still closed, by URL as much as by
    // the sidebar.
    actingAs($member)->get(route('portal.estrategia'))->assertNotFound();
});

test('the client never sees the 48-item list', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);

    // 28 optional things nobody promised would read as 28 things missing.
    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertDontSee('Prisma de Kapferer')
        ->assertDontSee('Service design');
});

/*
|--------------------------------------------------------------------------
| The brand, writing itself
|--------------------------------------------------------------------------
*/

test('the page never counts what is missing', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en Guadalajara.',
        DeliverableItem::Colores->value => 'Mostaza #ECBB12',
    ]);

    // "2 de 48" read as a brand 4% done. 28 of the 48 are optional and most
    // brands never get all of them, so the fraction described a project that
    // does not exist — and there is nothing the client can do with it either
    // way. Counting belongs to the admin's board.
    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Nació en Guadalajara.')
        ->assertDontSee('de 48');
});

test('an untouched brand says nothing is written yet', function () {
    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Todavía no hay nada escrito');
});

test('only entregables with content appear', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => 'Nació en una cocina de Guadalajara.',
        DeliverableItem::Colores->value => 'Mostaza #ECBB12 — acento',
    ]);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Relato de marca')
        ->assertSee('Nació en una cocina de Guadalajara.')
        ->assertSee('Colores')
        // An empty one is absent, not shown as a gap — the opposite of what
        // the assistant reads, and deliberately so.
        ->assertDontSee('Tono de comunicación')
        ->assertDontSee('NO DEFINIDO');
});

test('a link in an entregable is rendered as a link', function () {
    $this->client->deliverables->update([
        DeliverableItem::IdentificativoPrincipal->value => 'El logo: https://breakfast.test/archivos/1',
    ]);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('href="https://breakfast.test/archivos/1"', escape: false);
});

test('markup in an entregable is escaped, not rendered', function () {
    $this->client->deliverables->update([
        DeliverableItem::Relato->value => '<script>alert(1)</script>',
    ]);

    // "It comes from staff" is not a security model.
    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('the brand page needs the section', function () {
    $member = User::factory()->clientMember($this->client->id, [])->create();

    actingAs($member)->get(route('portal.estrategia'))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| What the assistant is told about the process
|--------------------------------------------------------------------------
*/

test('the process block names the step, the counts and the next meeting', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);
    $this->steps->start($this->client, ProcessStep::Arquitectura);
    $this->client->meetings()->create([
        'title' => 'Revisión de territorio',
        'scheduled_at' => now()->addWeek(),
    ]);

    $prompt = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    expect($prompt)->toContain('Proceso del proyecto')
        ->toContain('EN CURSO')
        ->toContain('Identidad de marca')
        ->toContain('de 19 obligatorios definidos')
        ->toContain('Revisión de territorio');
});

test('no meeting is stated as such rather than left silent', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Algo.']);

    $prompt = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    // Silence gets filled in with something plausible; an explicit absence
    // does not. Same rule as NO DEFINIDO on the entregables.
    expect($prompt)->toContain('Próxima reunión: NO DEFINIDA');
});

test('the process block carries no clock', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Algo.']);
    $this->steps->start($this->client, ProcessStep::Arquitectura);

    $prompt = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    // This lands in block 2, whose exact bytes are the caching mechanism.
    // "hace dos semanas" would change every fortnight and drop the hit rate
    // to zero silently.
    expect($prompt)->not->toContain('hace ')
        ->not->toContain('faltan ')
        ->not->toContain('Hoy es');
});

test('the process block does not make an empty brand look usable', function () {
    $repository = app(BrandContextRepository::class);

    // Added unconditionally it would make every brand report usable context,
    // and the assistant would be offered with nothing behind it.
    expect($repository->hasUsableContext($this->client))->toBeFalse();

    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Algo.']);

    expect($repository->hasUsableContext($this->client->fresh()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The toolkit, as the second tier
|--------------------------------------------------------------------------
| clients.document_digest was written by the onboarding assistant and read by
| nothing at all, so Brandy had never seen a brandbook — only the entregables a
| person accepted out of one. These pin the tier being there AND staying below
| the entregables, because the whole point is that it is backup.
*/

test('the toolkit reaches the assistant, below the entregables', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);
    $this->client->update(['document_digest' => "## brandbook.pdf\nEl claim es «Café de verdad»."]);

    $prompt = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    expect($prompt)
        ->toContain('Café de verdad')
        // It says what it is in its own first lines, where the framing cannot
        // be separated from the text it governs.
        ->toContain('material de RESPALDO')
        ->toContain('Los entregables mandan');

    // ⚠️ ORDER, not merely presence. BrandContext::make() ksorts the titles, so
    // the numbering is what holds the hierarchy — not the alphabet, and not the
    // order this repository happens to insert them in.
    expect(mb_strpos($prompt, 'Entregables de la marca'))
        ->toBeLessThan(mb_strpos($prompt, 'Toolkit de la marca'));
});

test('a toolkit alone does not make the assistant worth offering', function () {
    // A brand with an uploaded document and not one entregable written. The
    // digest must not be what turns the assistant on: it is a reading nobody
    // reviewed, which is the one thing this app exists to keep out of a claim.
    $this->client->update(['document_digest' => 'Algo que decía el PDF.']);

    $repository = app(BrandContextRepository::class);

    expect($repository->hasUsableContext($this->client->fresh()))->toBeFalse()
        ->and($repository->for($this->client->fresh())->toPrompt())
        ->not->toContain('Algo que decía el PDF');
});

test('reading a toolkit moves the context version', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Algo.']);

    $before = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    $this->travel(2)->minutes();
    $this->client->update(['document_digest' => 'Lo que decía el brandbook.']);

    $after = app(BrandContextRepository::class)->for($this->client->fresh())->toPrompt();

    // The version line is printed into block 2, so it has to move when block 2
    // does. Naming the entregables' timestamp alone would claim the context had
    // not changed while a whole tier had just been added to it.
    expect($after)->not->toBe($before)
        ->and($after)->toContain('Versión del contexto');
});
