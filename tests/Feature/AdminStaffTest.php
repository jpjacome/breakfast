<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('the roster lists breakfast staff and nobody else', function () {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada']));
    User::factory()->equipo()->create(['name' => 'Beto']);
    // A distinctive name: "Cliente" would collide with the sidebar's Clientes.
    User::factory()->clientOwner(Client::factory()->create())->create(['name' => 'Zoraida']);

    $this->get(route('admin.staff.index'))
        ->assertOk()
        ->assertSee('Ada')
        ->assertSee('Beto')
        ->assertDontSee('Zoraida');
});

test('an admin can add someone to the team', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.staff.store'), [
        'name' => 'María García',
        'email' => 'maria@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
    ])->assertRedirect();

    $user = User::firstWhere('email', 'maria@vamosdebreakfast.test');

    // Staff belong to no brand — asserted on the memberships, since
    // users.client_id was dropped on 2026-09-15 and reading a column that is
    // not there would pass for the wrong reason.
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Equipo)
        ->and($user->brands)->toBeEmpty();

    Notification::assertSentTo($user, StaffInvitation::class);
});

test('the temporary password is flashed once and actually works', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.staff.store'), [
        'name' => 'Temp',
        'email' => 'temp@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
    ]);

    $password = session('temp_password');
    expect($password)->toBeString()->not->toBeEmpty();

    $user = User::firstWhere('email', 'temp@vamosdebreakfast.test');
    expect(Hash::check($password, $user->password))->toBeTrue();
});

test('client roles cannot be assigned through the staff form', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    foreach (UserRole::clientRoles() as $role) {
        $this->post(route('admin.staff.store'), [
            'name' => 'Colado',
            'email' => "colado-{$role->value}@vamosdebreakfast.test",
            'role' => $role->value,
        ])->assertSessionHasErrors('role');
    }

    expect(User::where('email', 'like', 'colado-%')->exists())->toBeFalse();
});

test('an admin can edit a teammate', function () {
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->equipo()->create();

    $this->put(route('admin.staff.update', $member), [
        'name' => 'Nombre Nuevo',
        'role' => UserRole::Admin->value,
    ])->assertRedirect();

    expect($member->fresh())
        ->name->toBe('Nombre Nuevo')
        ->role->toBe(UserRole::Admin);
});

test('not even an admin changes an address — a posted one is ignored', function () {
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->equipo()->create(['email' => 'suyo@vamosdebreakfast.test']);

    // The form no longer offers the field. This is the crafted request that
    // would slip past a rule kept only in the markup.
    $this->put(route('admin.staff.update', $member), [
        'name' => 'Nombre Nuevo',
        'email' => 'otro@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
    ])->assertRedirect();

    expect($member->fresh())
        ->name->toBe('Nombre Nuevo')
        ->email->toBe('suyo@vamosdebreakfast.test');
});

test('an admin cannot change their own role', function () {
    $this->actingAs($admin = User::factory()->admin()->create());
    User::factory()->admin()->create(); // so the last-admin guard is not what fires

    $this->put(route('admin.staff.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => UserRole::Equipo->value,
    ])->assertForbidden();

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('an admin can still fix their own name, but not their own address', function () {
    $this->actingAs($admin = User::factory()->admin()->create(['email' => 'suyo@vamosdebreakfast.test']));

    $this->put(route('admin.staff.update', $admin), [
        'name' => 'Se Corrigió',
        'email' => 'corregido@vamosdebreakfast.test',
        'role' => UserRole::Admin->value, // unchanged — the form posts it back
    ])->assertRedirect();

    expect($admin->fresh())
        ->name->toBe('Se Corrigió')
        ->email->toBe('suyo@vamosdebreakfast.test');
});

test('demoting another admin is allowed — the acting one is always left', function () {
    $this->actingAs(User::factory()->admin()->create());
    $other = User::factory()->admin()->create();

    $this->put(route('admin.staff.update', $other), [
        'name' => $other->name,
        'email' => $other->email,
        'role' => UserRole::Equipo->value,
    ])->assertRedirect();

    expect($other->fresh()->role)->toBe(UserRole::Equipo);
});

test('an admin can remove a teammate but not themselves', function () {
    $this->actingAs($admin = User::factory()->admin()->create());
    $member = User::factory()->equipo()->create();

    $this->delete(route('admin.staff.destroy', $member))->assertRedirect();
    expect(User::find($member->id))->toBeNull();

    $this->delete(route('admin.staff.destroy', $admin))->assertForbidden();
    expect(User::find($admin->id))->not->toBeNull();
});

test('a client user cannot be managed as staff', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = User::factory()->clientOwner(Client::factory()->create())->create();

    $this->put(route('admin.staff.update', $client), [
        'name' => 'Ascendido',
        'email' => $client->email,
        'role' => UserRole::Admin->value,
    ])->assertNotFound();

    $this->post(route('admin.staff.resend', $client))->assertNotFound();
    $this->delete(route('admin.staff.destroy', $client))->assertNotFound();

    expect($client->fresh()->role)->toBe(UserRole::ClienteOwner);
});

test('the equipo role reads the roster but cannot change it', function () {
    Notification::fake();

    $this->actingAs(User::factory()->equipo()->create());
    $member = User::factory()->equipo()->create();

    $this->get(route('admin.staff.index'))->assertOk();

    $this->post(route('admin.staff.store'), [
        'name' => 'Colado',
        'email' => 'colado@vamosdebreakfast.test',
        'role' => UserRole::Admin->value,
    ])->assertForbidden();

    $this->put(route('admin.staff.update', $member), [
        'name' => 'Cambiado',
        'email' => $member->email,
        'role' => UserRole::Admin->value,
    ])->assertForbidden();

    $this->delete(route('admin.staff.destroy', $member))->assertForbidden();

    expect(User::where('email', 'colado@vamosdebreakfast.test')->exists())->toBeFalse()
        ->and($member->fresh()->role)->toBe(UserRole::Equipo);
});

test('a client user cannot reach the staff page at all', function () {
    $this->actingAs(User::factory()->clientOwner(Client::factory()->create())->create());

    $this->get(route('admin.staff.index'))->assertNotFound();
    $this->post(route('admin.staff.store'), [
        'name' => 'Colado',
        'email' => 'colado@lamarca.test',
        'role' => UserRole::Admin->value,
    ])->assertNotFound();
});

/* -------------------------------------------------------------------------
 | Brand assignment
 |
 | What the checklist on the form actually writes. Whether those assignments
 | are then enforced is AdminBrandScopeTest.
 ------------------------------------------------------------------------- */

test('an admin picks the brands a new teammate can reach', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $mine = Client::factory()->create();
    $theirs = Client::factory()->create();

    $this->post(route('admin.staff.store'), [
        'name' => 'María García',
        'email' => 'maria@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
        'clients' => [$mine->id],
    ])->assertRedirect();

    $user = User::firstWhere('email', 'maria@vamosdebreakfast.test');

    expect($user->covers($mine))->toBeTrue()
        ->and($user->covers($theirs))->toBeFalse();
});

test('a teammate created with no brands covers none', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    // An unticked checkbox group posts nothing at all — that is "none", not
    // "field missing, leave it alone".
    $this->post(route('admin.staff.store'), [
        'name' => 'Sin Marcas',
        'email' => 'sinmarcas@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
    ])->assertRedirect();

    expect(User::firstWhere('email', 'sinmarcas@vamosdebreakfast.test')->covers($client))
        ->toBeFalse();
});

test('editing a teammate replaces their brands rather than adding to them', function () {
    $this->actingAs(User::factory()->admin()->create());

    $member = User::factory()->equipo()->create();
    $before = Client::factory()->create();
    $after = Client::factory()->create();

    $member->assignedClients()->sync([$before->id]);

    $this->put(route('admin.staff.update', $member), [
        'name' => $member->name,
        'email' => $member->email,
        'role' => UserRole::Equipo->value,
        'clients' => [$after->id],
    ])->assertRedirect();

    $member->refresh();

    expect($member->covers($after))->toBeTrue()
        ->and($member->covers($before))->toBeFalse();
});

test('a brand that does not exist cannot be assigned', function () {
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->equipo()->create();

    $this->put(route('admin.staff.update', $member), [
        'name' => $member->name,
        'email' => $member->email,
        'role' => UserRole::Equipo->value,
        'clients' => [999_999],
    ])->assertSessionHasErrors('clients.0');

    expect($member->fresh()->assignedClients)->toBeEmpty();
});

test('the equipo role cannot assign brands to anyone', function () {
    $this->actingAs(User::factory()->equipo()->create());

    $member = User::factory()->equipo()->create();
    $client = Client::factory()->create();

    $this->put(route('admin.staff.update', $member), [
        'name' => $member->name,
        'email' => $member->email,
        'role' => UserRole::Equipo->value,
        'clients' => [$client->id],
    ])->assertForbidden();

    expect($member->fresh()->covers($client))->toBeFalse();
});

test('a new teammate can sign in and lands on the back-office', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.staff.store'), [
        'name' => 'Ingresa',
        'email' => 'ingresa@vamosdebreakfast.test',
        'role' => UserRole::Equipo->value,
    ]);

    $password = session('temp_password');

    auth()->logout();
    $this->flushSession();

    $this->post('/login', ['email' => 'ingresa@vamosdebreakfast.test', 'password' => $password])
        ->assertRedirect(route('admin.home'));

    $this->assertAuthenticated();
});
