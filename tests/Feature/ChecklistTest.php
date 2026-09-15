<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Models\ChecklistTick;
use App\Models\Client;
use App\Models\User;
use App\Services\Checklist;

use function Pest\Laravel\actingAs;

/**
 * The client ticking their implementation checklist — SEG-05.
 *
 * ⚠️ THIS IS THE FIRST CLIENT WRITE PATH IN THE PORTAL BESIDES PERFIL, so half
 * of these tests are about the edges of that permission rather than about
 * checkboxes: what a client may write, what they may not, and that the
 * entregable's text stays Breakfast's.
 */
beforeEach(function () {
    $this->client = Client::factory()->create();

    $this->client->deliverables->update([
        DeliverableItem::ChecklistImplementacion->value => implode("\n", [
            '- Publicar el manual de marca',
            '- Actualizar el perfil de Instagram',
            '- Imprimir la papelería',
        ]),
    ]);

    $this->owner = User::factory()->clientOwner()->create([
        'client_id' => $this->client->id,
        'permissions' => [PortalSection::Estrategia->value => AccessLevel::Read->value],
    ]);
});

/** The key the form would post for a given line. */
function keyFor(string $label): string
{
    return Checklist::key($label);
}

/* --- reading the text ----------------------------------------------------- */

test('bullets, numbers and bare lines are all one kind of item', function () {
    $list = Checklist::fromText("- Uno\n2. Dos\n• Tres\nCuatro\n\n   \n");

    expect($list->count())->toBe(4)
        ->and(collect($list->items)->map->label->all())
        ->toBe(['Uno', 'Dos', 'Tres', 'Cuatro']);
});

test('a markdown tick in the text is not read as done', function () {
    // The state lives in checklist_ticks. Reading it from the text as well
    // would be two truths about the same thing.
    $list = Checklist::fromText('[x] Publicar el manual');

    expect($list->items[0]->label)->toBe('Publicar el manual');
});

test('the same line twice is one item', function () {
    // Two checkboxes sharing a key would tick and untick each other.
    expect(Checklist::fromText("- Uno\n- Uno")->count())->toBe(1);
});

/* --- ticking --------------------------------------------------------------- */

test('the client can tick an item and it stays ticked', function () {
    $key = keyFor('Publicar el manual de marca');

    actingAs($this->owner)
        ->post(route('portal.estrategia.checklist'), ['items' => [$key]])
        ->assertRedirect();

    expect($this->client->checklistTicks()->pluck('item_key')->all())->toBe([$key]);

    actingAs($this->owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Publicar el manual de marca');
});

test('unticking is a key that does not come back', function () {
    $key = keyFor('Publicar el manual de marca');

    actingAs($this->owner)->post(route('portal.estrategia.checklist'), ['items' => [$key]]);
    expect($this->client->checklistTicks()->count())->toBe(1);

    // The whole list posts every time, so an empty post means nothing ticked.
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), ['items' => []]);
    expect($this->client->checklistTicks()->count())->toBe(0);
});

test('who ticked it and when are recorded, for Breakfast to follow', function () {
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Imprimir la papelería')],
    ]);

    $tick = $this->client->checklistTicks()->firstOrFail();

    expect($tick->checked_by)->toBe($this->owner->id)
        ->and($tick->checked_at)->not->toBeNull();
});

/* --- what the client may NOT do ------------------------------------------- */

test('a key that is not in the brand checklist ticks nothing', function () {
    // ⚠️ The client answers the questions Breakfast asked. They cannot add
    // questions of their own by posting keys.
    actingAs($this->owner)
        ->post(route('portal.estrategia.checklist'), ['items' => [keyFor('Comprar un yate')]])
        ->assertRedirect();

    expect(ChecklistTick::count())->toBe(0);
});

test('ticking cannot change a word of the entregable', function () {
    $before = $this->client->deliverables->fresh()
        ->value(DeliverableItem::ChecklistImplementacion);

    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Publicar el manual de marca')],
    ]);

    expect($this->client->deliverables->fresh()->value(DeliverableItem::ChecklistImplementacion))
        ->toBe($before);
});

test('somebody without Estrategia cannot tick, and gets a 404 rather than a 403', function () {
    $stranger = User::factory()->clientOwner()->create([
        'client_id' => $this->client->id,
        'permissions' => [],
    ]);

    actingAs($stranger)
        ->post(route('portal.estrategia.checklist'), ['items' => [keyFor('Imprimir la papelería')]])
        // 404, not 403: a member who was never given the section should not
        // learn it exists. CLAUDE.md §6.
        ->assertNotFound();

    expect(ChecklistTick::count())->toBe(0);
});

test('one brand cannot tick another brand checklist', function () {
    $other = Client::factory()->create();
    $other->deliverables->update([
        DeliverableItem::ChecklistImplementacion->value => '- Publicar el manual de marca',
    ]);

    // The same sentence, so the same key — the only thing keeping these apart
    // is that the write is scoped to the user's own brand.
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Publicar el manual de marca')],
    ]);

    expect($other->checklistTicks()->count())->toBe(0)
        ->and($this->client->checklistTicks()->count())->toBe(1);
});

/* --- when Breakfast edits the list ---------------------------------------- */

test('reordering the checklist keeps every tick', function () {
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Publicar el manual de marca'), keyFor('Imprimir la papelería')],
    ]);

    $this->client->deliverables->update([
        DeliverableItem::ChecklistImplementacion->value => implode("\n", [
            '- Imprimir la papelería',
            '- Publicar el manual de marca',
            '- Actualizar el perfil de Instagram',
        ]),
    ]);

    // Keyed on the line, not its position: nothing was re-posted, and both
    // ticks are still there.
    expect($this->client->checklistTicks()->count())->toBe(2);

    $ticked = $this->client->checklistTicks()->pluck('item_key')->all();
    $list = Checklist::for($this->client->deliverables->fresh());

    expect(array_intersect($ticked, $list->keys()))->toHaveCount(2);
});

test('tidying a line keeps its tick, rewriting it does not', function () {
    $key = keyFor('Publicar el manual de marca');

    // Punctuation, case and spacing are decoration…
    expect(keyFor('  Publicar el Manual de marca.  '))->toBe($key)
        // …but the words are the item. An edited line is a different item, and
        // carrying the tick over would claim somebody confirmed a sentence
        // they never read.
        ->and(keyFor('Publicar el brandbook'))->not->toBe($key);
});

test('a tick against a deleted line survives the line coming back', function () {
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Imprimir la papelería')],
    ]);

    // Breakfast removes that line…
    $this->client->deliverables->update([
        DeliverableItem::ChecklistImplementacion->value => '- Publicar el manual de marca',
    ]);

    // …and the client ticks what is left.
    //
    // ⚠️ ->fresh() MATTERS HERE AND ONLY IN THE TEST. actingAs() reuses one
    // User instance across requests, so its cached client->deliverables
    // relation would still hold the text from before the edit above — and the
    // controller would delete against a stale list. A real request builds its
    // models from the session every time, so this is an artefact of the test
    // and not something the endpoint has to defend against.
    actingAs($this->owner->fresh())->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Publicar el manual de marca')],
    ]);

    expect($this->client->checklistTicks()->count())->toBe(2);
});

/* --- the other side -------------------------------------------------------- */

test('Breakfast sees what the brand ticked, read-only', function () {
    actingAs($this->owner)->post(route('portal.estrategia.checklist'), [
        'items' => [keyFor('Publicar el manual de marca')],
    ]);

    $admin = User::factory()->admin()->create();

    actingAs($admin)
        ->get(route('admin.clients.show', $this->client))
        ->assertOk()
        ->assertSee('Checklist de implementación')
        ->assertSee('Publicar el manual de marca')
        // Who ticked it — the "seguimiento" the report asked for.
        ->assertSee($this->owner->firstName())
        // No checkboxes on this side: the ticks are the client's to make.
        ->assertDontSee('name="items[]"', escape: false);
});
