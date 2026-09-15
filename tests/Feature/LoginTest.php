<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the login screen renders inside the site layout', function () {
    // /login used to be a split-screen of its own. It is a page of the website
    // now, so what proves it "renders on the brand system" is the shared
    // navbar and footer around it, not the copy the old panel carried.
    $this->get('/login')
        ->assertOk()
        ->assertSee('Bienvenido de vuelta', escape: false)
        ->assertSee('class="navbar"', escape: false)
        ->assertSee('class="footer"', escape: false)
        ->assertSee('name="password"', escape: false);
});

test('a user can log in and lands on the portal', function () {
    $user = User::factory()->create([
        'email' => 'test@lamarca.com',
        'password' => Hash::make('breakfast2026'),
    ]);

    $this->post('/login', [
        'email' => 'test@lamarca.com',
        'password' => 'breakfast2026',
    ])->assertRedirect('/portal');

    $this->assertAuthenticatedAs($user);
});

test('signing in is what verifies the address', function () {
    // The invitation went to that address and the password came from the link
    // inside it, so arriving here at all is the proof — see
    // App\Listeners\MarkEmailVerifiedOnLogin.
    $user = User::factory()->unverified()->create([
        'email' => 'recien@lamarca.com',
        'password' => Hash::make('breakfast2026'),
    ]);

    expect($user->email_verified_at)->toBeNull();

    $this->post('/login', [
        'email' => 'recien@lamarca.com',
        'password' => 'breakfast2026',
    ])->assertRedirect();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('a failed sign-in verifies nothing', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'recien@lamarca.com',
        'password' => Hash::make('breakfast2026'),
    ]);

    $this->post('/login', [
        'email' => 'recien@lamarca.com',
        'password' => 'wrong-password',
    ]);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('a bad password is rejected', function () {
    User::factory()->create([
        'email' => 'test@lamarca.com',
        'password' => Hash::make('breakfast2026'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => 'test@lamarca.com',
        'password' => 'wrong-password',
    ])->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the portal is closed to guests', function () {
    $this->get('/portal')->assertRedirect('/login');
});

test('a signed-in visitor asking for /login is sent to their own dashboard', function () {
    // Not to the marketing home. Laravel's guest middleware falls back to the
    // route named "home", which is the public site — so without the
    // redirectUsing() in FortifyServiceProvider this lands on "/" and the
    // account icon in the navbar looks broken.
    $this->actingAs(User::factory()->admin()->create())
        ->get('/login')->assertRedirect(route('admin.home'));

    $this->actingAs(User::factory()->clientOwner()->create())
        ->get('/login')->assertRedirect(route('portal.home'));
});

test('the root url is the public site, signed in or not', function () {
    // ⚠️ THIS TEST USED TO ASSERT A REDIRECT TO /login, and it was written
    // before the marketing site was mounted at '/'. One document root serves
    // two products (CLAUDE.md §1): '/' is Breakfast's own front page and
    // belongs to nobody in particular, so it answers 200 for a stranger and
    // for a signed-in user alike. Where each ROLE lands after logging in is
    // User::homeRoute(), asserted below.
    $this->get('/')->assertOk();

    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();
    $this->actingAs(User::factory()->clientOwner()->create())->get('/')->assertOk();
});

test('signing in lands each role on its own home', function () {
    expect(User::factory()->admin()->create()->homeRoute())->toBe(route('admin.home'))
        ->and(User::factory()->clientOwner()->create()->homeRoute())->toBe(route('portal.home'));
});

test('registration is disabled by design', function () {
    // Breakfast invites its clients; there is no open sign-up.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
