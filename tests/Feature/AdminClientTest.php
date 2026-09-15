<?php

use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Admin\PortfolioSnapshot;
use App\Services\Ai\BrandContextRepository;
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

/* -------------------------------------------------------------------------
 | Editing a brand's own details
 |
 | Reported by a tester on 2026-08-14: the brand page showed name, industria
 | and contacto and offered no way to change any of them. They were writable
 | on the way in — /clientes/nueva and the draft autosave — and read-only
 | forever after, so correcting a contact meant editing the database.
 ------------------------------------------------------------------------- */

test('an admin edits a brand\'s details', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create([
        'name' => 'Patito',
        'industry' => 'Papelería',
        'contact_name' => null,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.clients.update', $client), [
            'brand' => [
                'name' => 'Patito Studio',
                'industry' => 'Diseño',
                'status' => $client->status->value,
                'contact_name' => 'María García',
                'contact_email' => 'maria@patito.com',
                'notes' => 'Cambió de contacto en agosto.',
            ],
        ])
        ->assertRedirect(route('admin.clients.show', $client));

    $client->refresh();

    expect($client->name)->toBe('Patito Studio')
        ->and($client->industry)->toBe('Diseño')
        ->and($client->contact_name)->toBe('María García')
        ->and($client->contact_email)->toBe('maria@patito.com')
        ->and($client->notes)->toBe('Cambió de contacto en agosto.');
});

test('renaming a brand does not move its address', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['name' => 'Patito', 'slug' => 'patito']);

    $this->actingAs($admin)->put(route('admin.clients.update', $client), [
        'brand' => ['name' => 'Patito Studio', 'status' => $client->status->value],
    ]);

    // The slug is the brand's address in the URLs people bookmark AND in
    // storage/app/marcas/{slug}. A corrected label must not strand a folder
    // of logos under the old name.
    expect($client->refresh()->slug)->toBe('patito');
});

test('clearing a contact stores null, not an empty string', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['contact_name' => 'María García']);

    $this->actingAs($admin)->put(route('admin.clients.update', $client), [
        'brand' => ['name' => $client->name, 'status' => $client->status->value, 'contact_name' => ''],
    ]);

    // "Sin contacto registrado" gets one representation instead of two.
    expect($client->refresh()->contact_name)->toBeNull();
});

test('a live brand cannot be sent back to being a draft', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['status' => ClientStatus::Activo]);

    $this->actingAs($admin)->put(route('admin.clients.update', $client), [
        'brand' => ['name' => $client->name, 'status' => ClientStatus::Borrador->value],
    ]);

    // Unfinished is a state a brand leaves once. Only the new-brand screen
    // understands a draft.
    expect($client->refresh()->status)->toBe(ClientStatus::Activo);
});

test('a brand needs a name', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create(['name' => 'Patito']);

    $this->actingAs($admin)
        ->put(route('admin.clients.update', $client), [
            'brand' => ['name' => '', 'status' => $client->status->value],
        ])
        ->assertSessionHasErrors('brand.name');

    expect($client->refresh()->name)->toBe('Patito');
});

test('equipo cannot edit a brand they are not on', function () {
    $staff = User::factory()->equipo()->create();
    $client = Client::factory()->create(['name' => 'Patito']);

    $this->actingAs($staff)
        ->put(route('admin.clients.update', $client), [
            'brand' => ['name' => 'Secuestrada', 'status' => $client->status->value],
        ])
        ->assertNotFound();

    expect($client->refresh()->name)->toBe('Patito');
});

test('the brand page offers the edit form', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.clients.show', $client))
        ->assertOk()
        ->assertSee('Editar datos de la marca')
        ->assertSee(route('admin.clients.update', $client), escape: false);
});

/*
|--------------------------------------------------------------------------
| Marca registrada — SEG-04 of the beta review
|--------------------------------------------------------------------------
| Three states, not two. The third one is the whole reason this is a nullable
| boolean rather than a checkbox, and it is what these tests are really pinning.
*/

test('marca registrada stores yes, no and sin definir as three distinct answers', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();

    $save = fn (string $value) => $this->actingAs($admin)
        ->put(route('admin.clients.update', $client), [
            'brand' => [
                'name' => $client->name,
                'status' => $client->status->value,
                'trademark_registered' => $value,
            ],
        ]);

    $save('1');
    expect($client->refresh()->trademark_registered)->toBeTrue()
        ->and($client->trademarkLabel())->toBe('Sí');

    $save('0');
    expect($client->refresh()->trademark_registered)->toBeFalse()
        ->and($client->trademarkLabel())->toBe('No');

    // ⚠️ The one that a boolean column would have got wrong: choosing "sin
    // definir" has to come back as null, not as false.
    $save('');
    expect($client->refresh()->trademark_registered)->toBeNull()
        ->and($client->trademarkLabel())->toBe('Sin definir');
});

test('a brand nobody has been asked about is sin definir, not No', function () {
    expect(Client::factory()->create()->trademarkLabel())->toBe('Sin definir');
});

test('marca registrada reaches both assistants', function () {
    $client = Client::factory()->create(['trademark_registered' => true]);

    // The ficha only rides along with real entregable content — see
    // BrandContextRepository::for(), which keeps hasUsableContext() honest.
    $client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Cuenca.']);

    // The client's own assistant, from its ficha…
    expect(app(BrandContextRepository::class)->for($client->fresh())->toPrompt())
        ->toContain('Marca registrada: Sí');

    // …and the dashboard's, from the portfolio snapshot's expanded ficha.
    expect(app(PortfolioSnapshot::class)->ficha($client))
        ->toContain('Marca registrada: Sí');
});

test('the client sees marca registrada on their own brand page', function () {
    $client = Client::factory()->create(['trademark_registered' => true]);
    $client->deliverables->update([DeliverableItem::Relato->value => 'Nació en Cuenca.']);

    $owner = User::factory()->clientOwner($client)->create([
        'permissions' => ['estrategia' => 'read'],
    ]);

    $this->actingAs($owner)
        ->get(route('portal.estrategia'))
        ->assertOk()
        ->assertSee('Marca registrada');
});
