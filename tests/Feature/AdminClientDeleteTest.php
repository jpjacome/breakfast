<?php

use App\Enums\AccessLevel;
use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte', 'slug' => 'cafeteria-norte']);
    $this->owner = User::factory()->clientOwner()->create([
        'client_id' => $this->client->id,
        'permissions' => [PortalSection::Estrategia->value => AccessLevel::Read->value],
    ]);
});

/*
|--------------------------------------------------------------------------
| Who may
|--------------------------------------------------------------------------
*/

test('an equipo member cannot archive a brand they work on', function () {
    $staff = User::factory()->equipo()->create();
    $this->client->staff()->attach($staff);

    // Working on a brand is not the same as removing it — the same split as
    // StaffController, where everybody sees the roster and only an Admin
    // changes it.
    actingAs($staff->fresh())
        ->delete(route('admin.clients.destroy', $this->client), ['confirmation' => 'Cafetería Norte'])
        ->assertForbidden();

    expect($this->client->fresh()->trashed())->toBeFalse();
});

test('a client user cannot reach it at all', function () {
    actingAs($this->owner)
        ->delete(route('admin.clients.destroy', $this->client), ['confirmation' => 'Cafetería Norte'])
        ->assertNotFound();
});

test('the brand name has to be typed', function () {
    actingAs($this->admin)
        ->delete(route('admin.clients.destroy', $this->client), ['confirmation' => 'otra cosa'])
        ->assertSessionHasErrors('confirmation');

    expect($this->client->fresh()->trashed())->toBeFalse();
});

test('the typed name is forgiving about case and spaces', function () {
    // Somebody copying the name out of the page should not be told they got
    // their own brand's name wrong.
    actingAs($this->admin)
        ->delete(route('admin.clients.destroy', $this->client), ['confirmation' => '  cafetería norte '])
        ->assertRedirect(route('admin.clients.index'));

    expect($this->client->fresh()->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Archiving
|--------------------------------------------------------------------------
*/

test('archiving hides the brand and shuts its people out immediately', function () {
    actingAs($this->owner)->get(route('portal.estrategia'))->assertOk();

    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ])->assertRedirect();

    // No user row was touched. accessTo() fails closed because the brand
    // behind a client user is gone, which is why restoring needs no undo.
    expect($this->owner->fresh()->permissions)
        ->toBe([PortalSection::Estrategia->value => AccessLevel::Read->value]);

    actingAs($this->owner->fresh())->get(route('portal.estrategia'))->assertNotFound();
    actingAs($this->admin)->get(route('admin.clients.index'))->assertOk()->assertDontSee('Cafetería Norte');
});

test('archiving keeps everything and restoring gives it all back', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Guadalajara.']);
    $this->client->meetings()->create(['title' => 'Revisión', 'scheduled_at' => now()->addWeek()]);

    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.restore', 'cafeteria-norte'))
        ->assertRedirect(route('admin.clients.show', $this->client));

    $restored = $this->client->fresh();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deliverables->value(DeliverableItem::Relato))->toBe('Nació en Guadalajara.')
        ->and($restored->meetings)->toHaveCount(1);

    actingAs($this->owner->fresh())->get(route('portal.estrategia'))->assertOk();
});

test('the papelera lists archived brands and nothing else', function () {
    $alive = Client::factory()->create(['name' => 'Sigue Viva']);

    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);

    actingAs($this->admin)
        ->get(route('admin.clients.index', ['status' => 'papelera']))
        ->assertOk()
        ->assertSee('Cafetería Norte')
        ->assertDontSee($alive->name);
});

/*
|--------------------------------------------------------------------------
| Destroying for good
|--------------------------------------------------------------------------
*/

test('purging takes the files off disk, not just the rows', function () {
    actingAs($this->admin)->post(route('admin.clients.assets.store', $this->client), [
        'files' => [UploadedFile::fake()->create('logo.png', 10)],
    ]);

    $path = $this->client->brandAssets()->firstOrFail()->path;
    Storage::disk('local')->assertExists($path);

    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);

    actingAs($this->admin)->delete(route('admin.clients.purge', 'cafeteria-norte'), [
        'confirmation' => 'Cafetería Norte',
    ])->assertRedirect();

    // The database cascade never loads a model, so BrandAsset's deleted() hook
    // would not fire and every file would be orphaned. DeleteClient::purge()
    // deletes them through Eloquent first for exactly this reason.
    Storage::disk('local')->assertMissing($path);
    expect(BrandAsset::count())->toBe(0);
});

test('purging takes the brand, its board and its people', function () {
    $this->client->deliverables->update([DeliverableItem::Relato->value => 'Algo.']);

    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);
    actingAs($this->admin)->delete(route('admin.clients.purge', 'cafeteria-norte'), [
        'confirmation' => 'Cafetería Norte',
    ]);

    // A client user is a person's access to ONE brand. With the brand gone the
    // account is a login that reaches nothing.
    expect(Client::withTrashed()->where('slug', 'cafeteria-norte')->count())->toBe(0)
        ->and(User::find($this->owner->id))->toBeNull()
        ->and(User::find($this->admin->id))->not->toBeNull();
});

test('purging needs the typed name too', function () {
    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);

    actingAs($this->admin)
        ->delete(route('admin.clients.purge', 'cafeteria-norte'), ['confirmation' => 'no'])
        ->assertStatus(422);

    expect(Client::withTrashed()->where('slug', 'cafeteria-norte')->count())->toBe(1);
});

test('a live brand cannot be purged, only an archived one', function () {
    // Nothing is ever one click from permanent: archiving is always the step
    // before this one.
    actingAs($this->admin)
        ->delete(route('admin.clients.purge', 'cafeteria-norte'), ['confirmation' => 'Cafetería Norte'])
        ->assertNotFound();

    expect($this->client->fresh())->not->toBeNull();
});

test('an equipo member cannot purge or restore', function () {
    actingAs($this->admin)->delete(route('admin.clients.destroy', $this->client), [
        'confirmation' => 'Cafetería Norte',
    ]);

    $staff = User::factory()->equipo()->create();

    actingAs($staff)->post(route('admin.clients.restore', 'cafeteria-norte'))->assertForbidden();
    actingAs($staff)->delete(route('admin.clients.purge', 'cafeteria-norte'), [
        'confirmation' => 'Cafetería Norte',
    ])->assertForbidden();

    expect(Client::withTrashed()->where('slug', 'cafeteria-norte')->count())->toBe(1);
});

test('the archive control is only on screen for an admin', function () {
    $staff = User::factory()->equipo()->create();
    $this->client->staff()->attach($staff);

    actingAs($this->admin)->get(route('admin.clients.show', $this->client))
        ->assertOk()->assertSee('archivar marca');

    actingAs($staff->fresh())->get(route('admin.clients.show', $this->client))
        ->assertOk()->assertDontSee('archivar marca');
});

/*
|--------------------------------------------------------------------------
| Drafts — the path that had no way out
|--------------------------------------------------------------------------
*/

test('a draft can be discarded from its row', function () {
    // The bug this pins: a draft's row links to the screen it was being
    // written on, and that screen had no delete — so there was no way to
    // remove one at all.
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), [
        'entregables' => [DeliverableItem::Relato->value => 'Algo escrito.'],
    ]);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    actingAs($this->admin)
        ->get(route('admin.clients.index'))
        ->assertOk()
        ->assertSee(route('admin.clients.draft.discard', $draft), escape: false);

    actingAs($this->admin)
        ->delete(route('admin.clients.draft.discard', $draft))
        ->assertRedirect(route('admin.clients.index'));

    // Discarded, not archived: an abandoned draft is clutter, and the papelera
    // would move the clutter rather than remove it.
    expect(Client::withTrashed()->find($draft->id))->toBeNull();
});

test('the draft screen offers a way out once there is something to throw away', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), ['name' => 'A medias']);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    actingAs($this->admin)
        ->get(route('admin.clients.create', ['borrador' => $draft->slug]))
        ->assertOk()
        ->assertSee('Descartar borrador');

    // Nothing written yet means nothing to discard — Cancelar already does it.
    actingAs($this->admin)
        ->get(route('admin.clients.create'))
        ->assertOk()
        ->assertDontSee('Descartar borrador');
});

test('discarding is refused on a brand that is not a draft', function () {
    // The route only handles drafts; a live brand goes through archiving,
    // which is recoverable and asks for the name.
    actingAs($this->admin)
        ->delete(route('admin.clients.draft.discard', $this->client))
        ->assertNotFound();

    expect($this->client->fresh())->not->toBeNull();
});

test('an equipo member on the brand can discard its draft', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), ['name' => 'A medias']);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();
    $staff = User::factory()->equipo()->create();
    $draft->staff()->attach($staff);

    // Unlike archiving a live brand, this is not Admin-only: the person who
    // started a half-filled form is usually the one who wants it gone, and
    // making them find an Admin is how the clutter accumulates.
    actingAs($staff->fresh())
        ->delete(route('admin.clients.draft.discard', $draft))
        ->assertRedirect();

    expect(Client::withTrashed()->find($draft->id))->toBeNull();
});

test('a draft of a brand you are not on stays out of reach', function () {
    actingAs($this->admin)->postJson(route('admin.clients.draft.save'), ['name' => 'A medias']);

    $draft = Client::where('status', ClientStatus::Borrador)->firstOrFail();

    actingAs(User::factory()->equipo()->create())
        ->delete(route('admin.clients.draft.discard', $draft))
        ->assertNotFound();

    expect(Client::find($draft->id))->not->toBeNull();
});
