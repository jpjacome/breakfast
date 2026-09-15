<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte', 'slug' => 'cafeteria-norte']);
});

/** The one way a file gets into a folder, shared with the process screen. */
function putFile(User $admin, Client $client, string $name = 'logo.png'): void
{
    actingAs($admin)->post(route('admin.clients.assets.store', $client), [
        'files' => [UploadedFile::fake()->image($name)],
    ]);
}

/*
|--------------------------------------------------------------------------
| The folder list
|--------------------------------------------------------------------------
*/

test('the index lists every brand as a folder', function () {
    Client::factory()->create(['name' => 'Panadería Sur']);

    actingAs($this->admin)
        ->get(route('admin.files.index'))
        ->assertOk()
        ->assertSee('Cafetería Norte')
        ->assertSee('Panadería Sur')
        // A brand with nothing in it is still a folder. Hiding it would mean
        // the only way to fill one is to have filled it already.
        ->assertSee('Vacía');
});

test('a folder is weighed by what is in it', function () {
    putFile($this->admin, $this->client);

    actingAs($this->admin)
        ->get(route('admin.files.index'))
        ->assertOk()
        ->assertSee('1 archivo');
});

test('the index searches by brand name', function () {
    Client::factory()->create(['name' => 'Panadería Sur']);

    actingAs($this->admin)
        ->get(route('admin.files.index', ['q' => 'Cafetería']))
        ->assertOk()
        ->assertSee('Cafetería Norte')
        ->assertDontSee('Panadería Sur');
});

/**
 * The list is a thin reading of covers(), like every other admin list: nobody
 * is shown a folder that opening would 404 on.
 */
test('equipo sees only the folders of brands they are on', function () {
    $otra = Client::factory()->create(['name' => 'Panadería Sur']);
    $equipo = User::factory()->equipo()->create();
    $equipo->assignedClients()->attach($this->client);

    actingAs($equipo)
        ->get(route('admin.files.index'))
        ->assertOk()
        ->assertSee('Cafetería Norte')
        ->assertDontSee('Panadería Sur');

    // And the hiding is not the control — the middleware is.
    actingAs($equipo)->get(route('admin.files.show', $otra))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| One folder
|--------------------------------------------------------------------------
*/

test('a folder shows its files', function () {
    putFile($this->admin, $this->client, 'manual-de-marca.png');

    actingAs($this->admin)
        ->get(route('admin.files.show', $this->client))
        ->assertOk()
        ->assertSee('manual-de-marca');
});

test('uploading from the file manager comes back to the file manager', function () {
    actingAs($this->admin)
        ->from(route('admin.files.show', $this->client))
        ->post(route('admin.clients.assets.store', $this->client), [
            'files' => [UploadedFile::fake()->image('logo.png')],
        ])
        ->assertRedirect(route('admin.files.show', $this->client));
});

test('deleting from the file manager comes back to the file manager', function () {
    putFile($this->admin, $this->client);
    $asset = $this->client->brandAssets()->firstOrFail();

    actingAs($this->admin)
        ->from(route('admin.files.show', $this->client))
        ->delete(route('admin.clients.assets.destroy', [$this->client, $asset]))
        ->assertRedirect(route('admin.files.show', $this->client));
});

/*
|--------------------------------------------------------------------------
| Who gets in
|--------------------------------------------------------------------------
*/

/** 404 rather than 403, like every other gate in the app. */
test('a client user cannot reach the file manager', function () {
    $owner = User::factory()->clientOwner()->create(['client_id' => $this->client->id]);

    actingAs($owner)->get(route('admin.files.index'))->assertNotFound();
    actingAs($owner)->get(route('admin.files.show', $this->client))->assertNotFound();
});
