<?php

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\ContextDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/* ---------------------------------------------------------------------
 | Access
 --------------------------------------------------------------------- */

test('guests are sent to the login screen', function () {
    $this->get('/admin')->assertRedirect('/login');
    $this->get('/admin/clientes')->assertRedirect('/login');
});

test('client users cannot reach the admin area', function () {
    $this->actingAs(User::factory()->clientOwner()->create());

    // 404 rather than 403 — a client learns nothing about what exists.
    $this->get('/admin')->assertNotFound();
    $this->get('/admin/clientes')->assertNotFound();
});

test('breakfast staff reach the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin')
        ->assertOk()
        ->assertSee('inicio', escape: false);
});

/* ---------------------------------------------------------------------
 | Login routing
 --------------------------------------------------------------------- */

test('an admin lands on /admin after logging in', function () {
    $user = User::factory()->admin()->create(['email' => 'a@breakfast.test']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('admin.home'));
});

test('a client user lands on /portal after logging in', function () {
    $user = User::factory()->clientOwner()->create(['email' => 'c@marca.test']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('portal.home'));
});

/* ---------------------------------------------------------------------
 | Creating clients
 --------------------------------------------------------------------- */

test('an admin can create a client and the slug is generated', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.clients.store'), [
        'name' => 'The Coffee Club',
        'industry' => 'Café',
        'status' => ClientStatus::Activo->value,
        'contact_email' => 'hola@coffee.test',
    ])->assertRedirect();

    $client = Client::firstWhere('name', 'The Coffee Club');

    expect($client)->not->toBeNull()
        ->and($client->slug)->toBe('the-coffee-club')
        ->and($client->status)->toBe(ClientStatus::Activo)
        ->and($client->onboarded_at)->not->toBeNull();
});

test('duplicate brand names get distinct slugs', function () {
    $this->actingAs(User::factory()->admin()->create());

    foreach (range(1, 2) as $i) {
        $this->post(route('admin.clients.store'), [
            'name' => 'Misma Marca',
            'status' => ClientStatus::Activo->value,
        ]);
    }

    expect(Client::pluck('slug')->all())->toBe(['misma-marca', 'misma-marca-2']);
});

test('a client requires a name', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.clients.store'), ['status' => ClientStatus::Activo->value])
        ->assertSessionHasErrors('name');

    expect(Client::count())->toBe(0);
});

test('the client list can be searched', function () {
    $this->actingAs(User::factory()->admin()->create());

    Client::factory()->create(['name' => 'Cafetería Norte']);
    Client::factory()->create(['name' => 'Zapatos Sur']);

    $this->get(route('admin.clients.index', ['q' => 'Cafetería']))
        ->assertOk()
        ->assertSee('Cafetería Norte')
        ->assertDontSee('Zapatos Sur');
});

/* ---------------------------------------------------------------------
 | Context documents
 --------------------------------------------------------------------- */

test('an admin can upload a context file', function () {
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.clients.context.store', $client), [
            'title' => 'Brief de marca 2026',
            'kind' => 'brief',
            'file' => UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf'),
        ])
        ->assertRedirect();

    $doc = ContextDocument::first();

    expect($doc)->not->toBeNull()
        ->and($doc->client_id)->toBe($client->id)
        ->and($doc->uploaded_by)->toBe($admin->id)
        ->and($doc->original_name)->toBe('brief.pdf')
        ->and($doc->processed_at)->toBeNull();

    Storage::disk('local')->assertExists($doc->path);
});

test('the stored path never contains the original filename', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.context.store', $client), [
        'title' => 'Raro',
        'kind' => 'otro',
        'file' => UploadedFile::fake()->create('../../evil name.pdf', 10, 'application/pdf'),
    ]);

    $doc = ContextDocument::first();

    expect($doc->path)->toStartWith("context/{$client->id}/")
        ->and($doc->path)->not->toContain('evil')
        ->and($doc->path)->not->toContain('..');
});

test('unsupported file types are rejected', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.context.store', $client), [
        'title' => 'Ejecutable',
        'kind' => 'otro',
        'file' => UploadedFile::fake()->create('virus.exe', 10),
    ])->assertSessionHasErrors('file');

    expect(ContextDocument::count())->toBe(0);
});

test('a context file can be downloaded and deleted', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.context.store', $client), [
        'title' => 'Estrategia',
        'kind' => 'estrategia',
        'file' => UploadedFile::fake()->create('estrategia.pdf', 50, 'application/pdf'),
    ]);

    $doc = ContextDocument::first();
    $path = $doc->path;

    $this->get(route('admin.clients.context.download', [$client, $doc]))
        ->assertOk()
        ->assertDownload('estrategia.pdf');

    $this->delete(route('admin.clients.context.destroy', [$client, $doc]))
        ->assertRedirect();

    expect(ContextDocument::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('a document cannot be reached through another client', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create());

    $owner = Client::factory()->create();
    $other = Client::factory()->create();

    $this->post(route('admin.clients.context.store', $owner), [
        'title' => 'Privado',
        'kind' => 'brief',
        'file' => UploadedFile::fake()->create('privado.pdf', 10, 'application/pdf'),
    ]);

    $doc = ContextDocument::first();

    $this->get(route('admin.clients.context.download', [$other, $doc]))->assertNotFound();
    $this->delete(route('admin.clients.context.destroy', [$other, $doc]))->assertNotFound();

    expect(ContextDocument::count())->toBe(1);
});

test('client users cannot upload context', function () {
    Storage::fake('local');

    $client = Client::factory()->create();
    $this->actingAs(User::factory()->clientOwner($client)->create());

    $this->post(route('admin.clients.context.store', $client), [
        'title' => 'Intento',
        'kind' => 'brief',
        'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ])->assertNotFound();

    expect(ContextDocument::count())->toBe(0);
});
