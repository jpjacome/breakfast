<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvitation;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The whole "olvidé mi contraseña" loop, walked end to end.
 *
 * AccountMailTest already covers the wording of the mail. What is proved here
 * is that the round trip actually completes: request a link, open the URL that
 * was mailed, set a password, and sign in with it — for every kind of account
 * there is.
 */

/** Every role, so "works for all users" is a fact rather than an assumption. */
dataset('every role', [
    'admin' => [fn () => User::factory()->admin()->create()],
    'equipo' => [fn () => User::factory()->equipo()->create()],
    'brand owner' => [fn () => User::factory()->clientOwner(Client::factory()->create())->create()],
    'brand member' => [fn () => User::factory()->create(['client_id' => Client::factory()])],
]);

it('walks the whole recovery loop', function (Closure $makeUser) {
    Notification::fake();

    $user = $makeUser();

    // 1. The form is reachable without being signed in.
    $this->get(route('password.request'))->assertOk();

    // 2. Asking for a link sends our mail.
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', trans('passwords.sent'));

    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    // 3. The link in that mail opens the "choose a password" screen.
    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertSee('Elige tu contraseña');

    // 4. Setting the password works.
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'una-clave-nueva-1',
        'password_confirmation' => 'una-clave-nueva-1',
    ])->assertSessionHas('status', trans('passwords.reset'));

    expect(Hash::check('una-clave-nueva-1', $user->fresh()->password))->toBeTrue();

    // 5. And the new password actually signs them in, at their own home.
    $this->post('/login', ['email' => $user->email, 'password' => 'una-clave-nueva-1'])
        ->assertRedirect($user->homeRoute());

    $this->assertAuthenticatedAs($user);
})->with('every role');

it('walks an invited user from the mail to their first sign-in', function () {
    Notification::fake();

    // The invitation is the same broker and the same two screens as a reset,
    // but it is the FIRST thing a client's user ever does with this app — the
    // one walk where nothing can be assumed to have worked before.
    $client = Client::factory()->create(['name' => 'The Roastery']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.clients.users.store', $client), [
            'name' => 'María Peña',
            'email' => 'maria@theroastery.test',
            'role' => UserRole::ClienteOwner->value,
        ])->assertSessionHasNoErrors();

    $invited = User::where('email', 'maria@theroastery.test')->firstOrFail();

    $token = null;
    Notification::assertSentTo($invited, ClientInvitation::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    // The link in the mail opens the screen where they pick a password.
    $this->post('/logout');

    $this->get(route('password.reset', ['token' => $token, 'email' => $invited->email]))
        ->assertOk()
        ->assertSee('Elige tu contraseña');

    // Choosing one lands them on /login rather than nowhere: the reset screen
    // has no navigation of its own, so a success that stayed there would be a
    // dead end with the door one URL away.
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $invited->email,
        'password' => 'mi-primera-clave-1',
        'password_confirmation' => 'mi-primera-clave-1',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', trans('passwords.reset'));

    // And the login screen says so, rather than looking like a fresh visit.
    $this->followingRedirects()
        ->post(route('password.update'), [
            'token' => Password::broker()->createToken($invited),
            'email' => $invited->email,
            'password' => 'mi-primera-clave-1',
            'password_confirmation' => 'mi-primera-clave-1',
        ])
        ->assertOk()
        ->assertSee(trans('passwords.reset'));

    $this->post('/login', [
        'email' => $invited->email,
        'password' => 'mi-primera-clave-1',
    ])->assertRedirect($invited->homeRoute());

    $this->assertAuthenticatedAs($invited);
});

it('does not lock anyone out just for asking for a link', function () {
    Notification::fake();

    $user = User::factory()->clientOwner(Client::factory()->create())->create([
        'password' => Hash::make('la-de-siempre-1'),
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    // Asking only writes a row in password_reset_tokens. The old password
    // keeps working until somebody actually completes the reset, so a
    // request made by mistake — or by somebody else — costs nothing.
    $this->post('/login', ['email' => $user->email, 'password' => 'la-de-siempre-1'])
        ->assertRedirect($user->homeRoute());

    $this->assertAuthenticatedAs($user);
});

it('spends the token so a leaked link cannot be replayed', function () {
    Notification::fake();

    $user = User::factory()->admin()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $reset = fn (string $password) => $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $reset('la-primera-vez-1')->assertSessionHas('status', trans('passwords.reset'));
    $reset('la-segunda-vez-1')->assertSessionHasErrors('email');

    // The second attempt must not have taken.
    expect(Hash::check('la-primera-vez-1', $user->fresh()->password))->toBeTrue();
});

it('still works six days later, which is why the window was widened', function () {
    $user = User::factory()->equipo()->create();
    $token = Password::broker()->createToken($user);

    // The case that made 60 minutes unusable: invited Friday, opened the
    // following week.
    $this->travel(6)->days();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'llegue-tarde-pero-1',
        'password_confirmation' => 'llegue-tarde-pero-1',
    ])->assertSessionHas('status', trans('passwords.reset'));

    expect(Hash::check('llegue-tarde-pero-1', $user->fresh()->password))->toBeTrue();
});

it('refuses a link past the window', function () {
    $user = User::factory()->equipo()->create();
    $token = Password::broker()->createToken($user);

    // config/auth.php: the token is good for seven days.
    $this->travel(8)->days();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'demasiado-tarde-1',
        'password_confirmation' => 'demasiado-tarde-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('demasiado-tarde-1', $user->fresh()->password))->toBeFalse();
});

it('refuses a token minted for somebody else', function () {
    $victim = User::factory()->admin()->create();
    $attacker = User::factory()->clientOwner(Client::factory()->create())->create();

    $token = Password::broker()->createToken($attacker);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $victim->email,
        'password' => 'no-es-tuya-1',
        'password_confirmation' => 'no-es-tuya-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('no-es-tuya-1', $victim->fresh()->password))->toBeFalse();
});

it('enforces the password rules on the new password', function () {
    Notification::fake();

    $user = User::factory()->admin()->create();
    $token = Password::broker()->createToken($user);

    // Too short for Password::default().
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertSessionHasErrors('password');

    // Confirmation must match.
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'una-clave-buena-1',
        'password_confirmation' => 'otra-cosa-distinta-1',
    ])->assertSessionHasErrors('password');
});

it('does not reveal whether an address has an account', function () {
    Notification::fake();

    // An enumeration oracle on this form would leak the whole client list.
    $this->post(route('password.email'), ['email' => 'nadie@vamosdebreakfast.test'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

it('sends the mail in Spanish with a working link for staff, who have no brand', function () {
    // Staff carry client_id = null. The invitation mail needs a Client and has
    // its own class for that reason; the reset mail must not.
    $user = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $token = Password::broker()->createToken($user);

    $body = (string) (new ResetPasswordLink($token))->toMail($user)->render();

    expect($body)->toContain('Ada Admin')
        ->and($body)->toContain('Crear contraseña nueva')
        ->and($body)->toContain(urlencode($user->email))
        ->and($body)->toContain(route('password.reset', ['token' => $token], absolute: false));
});
