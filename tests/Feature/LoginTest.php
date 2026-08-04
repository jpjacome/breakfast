<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the login screen renders on the brand system', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('entrar', escape: false)
        ->assertSee('img/logo.png', escape: false)
        ->assertSee('Una conversación larga con café.', escape: false);
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

test('registration is disabled by design', function () {
    // Breakfast invites its clients; there is no open sign-up.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
