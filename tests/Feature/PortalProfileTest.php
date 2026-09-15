<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;

/**
 * /portal/perfil — the one page in the portal a client can write to.
 *
 * It writes only you: your name and your password. Everything else on this side
 * is Breakfast writing and the brand reading, which is why Perfil is the single
 * always-on section that carries Write.
 *
 * Both forms post to Fortify's own endpoints, so what is pinned here is that
 * they are reachable from this page, that they change what they say they change,
 * and — the part that is ours — that a client cannot use them to widen their
 * own access or take over their address.
 */
beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);

    $this->member = User::factory()->create([
        'client_id' => $this->client->id,
        'name' => 'María Peña',
        'email' => 'maria@cafeterianorte.test',
        'password' => Hash::make('la-de-siempre-1'),
        'permissions' => [PortalSection::Estrategia->value => AccessLevel::Read->value],
    ]);
});

test('the page opens for everybody in the brand', function () {
    // Perfil is always-on: it is not in the permissions map at all, so a member
    // granted nothing still reaches their own account.
    $this->member->update(['permissions' => []]);

    actingAs($this->member)
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertSee('Contraseña')
        ->assertSee('María Peña')
        ->assertSee('maria@cafeterianorte.test');
});

test('it is no longer the placeholder it was', function () {
    actingAs($this->member)
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertDontSee('Esta sección se construye en la siguiente fase')
        ->assertSee(route('user-password.update'), escape: false);
});

test('the name changes', function () {
    actingAs($this->member)->put(route('user-profile-information.update'), [
        'name' => 'María Peña Ruiz',
    ])->assertSessionHasNoErrors();

    expect($this->member->fresh()->name)->toBe('María Peña Ruiz');
});

test('the address cannot be changed from here', function () {
    // The endpoint takes the name and nothing else — see
    // App\Actions\Fortify\UpdateUserProfileInformation. The address is the
    // account's identity: the username, and the only way back in through a
    // reset link. A form that does not show it must not accept it either.
    actingAs($this->member)->put(route('user-profile-information.update'), [
        'name' => 'María Peña',
        'email' => 'otra@cafeterianorte.test',
    ]);

    expect($this->member->fresh()->email)->toBe('maria@cafeterianorte.test');
});

test('the password changes and the new one signs them in', function () {
    actingAs($this->member)->put(route('user-password.update'), [
        'current_password' => 'la-de-siempre-1',
        'password' => 'una-clave-nueva-1',
        'password_confirmation' => 'una-clave-nueva-1',
    ])->assertSessionHasNoErrors();

    $this->post('/logout');

    $this->post('/login', [
        'email' => $this->member->email,
        'password' => 'una-clave-nueva-1',
    ])->assertRedirect($this->member->fresh()->homeRoute());

    $this->assertAuthenticatedAs($this->member->fresh());
});

test('the current password is required to change it', function () {
    actingAs($this->member)->put(route('user-password.update'), [
        'current_password' => 'no-es-esta-1',
        'password' => 'una-clave-nueva-1',
        'password_confirmation' => 'una-clave-nueva-1',
    ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

    expect(Hash::check('la-de-siempre-1', $this->member->fresh()->password))->toBeTrue();
});

test('a failed password change reports itself in its own bag, not the page-wide one', function () {
    // Two forms on one page. Fortify validates into a bag each and the page
    // prints each bag beside its own form — and the DEFAULT bag is what the
    // portal layout prints at the top, so anything landing there would mark up
    // a form the person never submitted.
    actingAs($this->member)->put(route('user-password.update'), [
        'current_password' => '',
        'password' => '',
        'password_confirmation' => '',
    ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

    expect(session('errors')->getBag('default')->isEmpty())->toBeTrue()
        ->and(session('errors')->getBag('updateProfileInformation')->isEmpty())->toBeTrue();
});

test('the page states what they can see, read off their own permissions', function () {
    actingAs($this->member)
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertSee(PortalSection::Estrategia->label())
        ->assertDontSee(PortalSection::Reuniones->label());
});

test('a member is told who widens their access, and it is not them', function () {
    actingAs($this->member)
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertSee('Nadie se amplía el acceso solo.');
});

test('a Breakfast admin opening this page gets a page, not a 500', function () {
    // Staff CAN walk the portal — the sidebar prints "Breakfast" where a
    // brand's name goes, so it is anticipated — and they carry client_id =
    // null. The first version of this page printed $client->name flat and
    // answered 500 for every admin who clicked Perfil.
    actingAs(User::factory()->admin()->create())
        ->get(route('portal.perfil'))
        ->assertOk()
        ->assertSee(route('admin.account'), escape: false)
        ->assertDontSee('Marca');
});
