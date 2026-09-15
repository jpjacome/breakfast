<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('an admin can create a user for a client', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'María García',
        'email' => 'maria@lamarca.test',
        'role' => UserRole::ClienteOwner->value,
    ])->assertRedirect();

    $user = User::firstWhere('email', 'maria@lamarca.test');

    // The brand is the MEMBERSHIP, not a column — users.client_id was dropped
    // on 2026-09-15 (docs/multimarca.md step 10).
    expect($user)->not->toBeNull()
        ->and($user->brands->contains($client))->toBeTrue()
        ->and($user->role)->toBe(UserRole::ClienteOwner);
});

test('the new user gets a link to set their own password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Nuevo',
        'email' => 'nuevo@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ]);

    Notification::assertSentTo(
        User::firstWhere('email', 'nuevo@lamarca.test'),
        ClientInvitation::class,
    );
});

test('the temporary password is flashed once and actually works', function () {
    Notification::fake();

    $this->actingAs($admin = User::factory()->admin()->create());
    $client = Client::factory()->create();

    $response = $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Temp',
        'email' => 'temp@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ]);

    $password = session('temp_password');
    expect($password)->toBeString()->not->toBeEmpty();

    // It must be a real credential, not decoration.
    $user = User::firstWhere('email', 'temp@lamarca.test');
    expect(Hash::check($password, $user->password))->toBeTrue();
});

test('breakfast roles cannot be assigned through the client form', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    foreach ([UserRole::Admin, UserRole::Equipo] as $role) {
        $this->post(route('admin.clients.users.store', $client), [
            'name' => 'Escalada',
            'email' => "escalada-{$role->value}@lamarca.test",
            'role' => $role->value,
        ])->assertSessionHasErrors('role');
    }

    expect(User::where('role', UserRole::Admin)->count())->toBe(1); // just the acting admin
    expect(User::where('role', UserRole::Equipo)->count())->toBe(0);
});

test('an address that already has an account is added to the brand, not refused', function () {
    // ⚠️ This used to assert a validation error, and that was right while an
    // account belonged to exactly one brand. ACC-01 inverted it: the same
    // person working with two brands is the case this app now has to serve,
    // and refusing the address was what forced them into a second one.
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $existing = User::factory()->create(['email' => 'tomado@lamarca.test']);

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Otro',
        'email' => 'tomado@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'tomado@lamarca.test')->count())->toBe(1)
        ->and($existing->fresh()->brands->contains($client))->toBeTrue();

    // No "create your password" mail: they already have one.
    Notification::assertNothingSent();
});

test('a client user cannot create accounts', function () {
    $client = Client::factory()->create();
    $this->actingAs(User::factory()->clientOwner($client)->create());

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Colado',
        'email' => 'colado@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ])->assertNotFound();

    expect(User::where('email', 'colado@lamarca.test')->exists())->toBeFalse();
});

test('a user cannot be managed through another client', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());

    $owner = Client::factory()->create();
    $other = Client::factory()->create();
    $user = User::factory()->clientOwner($owner)->create();

    $this->post(route('admin.clients.users.resend', [$other, $user]))->assertNotFound();
    $this->delete(route('admin.clients.users.destroy', [$other, $user]))->assertNotFound();

    expect(User::find($user->id))->not->toBeNull();
});

test('an admin can remove a client user but not themselves', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $client = Client::factory()->create();
    $user = User::factory()->clientOwner($client)->create();

    $this->delete(route('admin.clients.users.destroy', [$client, $user]))->assertRedirect();
    expect(User::find($user->id))->toBeNull();

    // The admin has no client, so the route 404s before the self-check —
    // but assert the account survives either way.
    $this->delete(route('admin.clients.users.destroy', [$client, $admin]));
    expect(User::find($admin->id))->not->toBeNull();
});

test('a created user can sign in and lands on the portal', function () {
    Notification::fake();

    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();

    $this->post(route('admin.clients.users.store', $client), [
        'name' => 'Ingresa',
        'email' => 'ingresa@lamarca.test',
        'role' => UserRole::ClienteOwner->value,
    ]);

    $password = session('temp_password');

    auth()->logout();
    $this->flushSession();

    $this->post('/login', ['email' => 'ingresa@lamarca.test', 'password' => $password])
        ->assertRedirect(route('portal.home'));

    $this->assertAuthenticated();
});
