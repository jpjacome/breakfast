<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvitation;
use App\Notifications\ResetPasswordLink;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('the invitation says an account was created, not that a password was reset', function () {
    $user = User::factory()->clientOwner()->create(['name' => 'María García']);

    $mail = (new ClientInvitation('tok3n', $user->client))->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toContain(config('app.name'))
        ->and($body)->toContain('María García')
        ->and($body)->toContain($user->client->name)
        ->and($body)->toContain('Crear mi contraseña');
});

test('the invitation links to the reset page with a usable token', function () {
    $user = User::factory()->clientOwner()->create();
    $token = Password::broker()->createToken($user);

    $body = (string) (new ClientInvitation($token, $user->client))->toMail($user)->render();

    expect($body)->toContain(urlencode($user->email))
        ->and(Password::broker()->tokenExists($user, $token))->toBeTrue();
});

test('a forgotten password sends our Spanish mail, not the English default', function () {
    Notification::fake();

    $user = User::factory()->clientOwner()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', trans('passwords.sent'));

    Notification::assertSentTo($user, ResetPasswordLink::class);
});

test('the reset mail is in Spanish, chrome included', function () {
    $user = User::factory()->clientOwner()->create(['name' => 'María García']);

    $body = (string) (new ResetPasswordLink('tok3n'))->toMail($user)->render();

    expect($body)->toContain('Crear contraseña nueva')
        // The wrapper's own lines come from Laravel and default to English —
        // lang/es.json is what keeps the footer and the fallback-URL note from
        // switching language halfway down the message.
        ->and($body)->toContain('Todos los derechos reservados')
        ->and($body)->not->toContain('All rights reserved')
        ->and($body)->not->toContain('trouble clicking');
});

/* -------------------------------------------------------------------------
 | Branding
 |
 | The theme lives in resources/views/vendor/mail, so every message gets it —
 | these assert on one invitation and one reset rather than on all three.
 ------------------------------------------------------------------------- */

test('every mail carries the Breakfast wordmark', function () {
    $user = User::factory()->clientOwner()->create();

    $invitation = (string) (new ClientInvitation('tok3n', $user->client))->toMail($user)->render();
    $reset = (string) (new ResetPasswordLink('tok3n'))->toMail($user)->render();

    foreach (['invitación' => $invitation, 'reset' => $reset] as $body) {
        expect($body)
            // Absolute, because mail has no page to resolve a relative src on.
            ->toContain('src="'.asset('img/logo.png').'"')
            // Gmail and Outlook block images by default, so the alt text is
            // what most people see the first time.
            ->toContain('alt="'.config('app.name').'"');
    }
});

test('the mails are painted in brand colours, not Laravel zinc', function () {
    $user = User::factory()->clientOwner()->create();

    $body = (string) (new ClientInvitation('tok3n', $user->client))->toMail($user)->render();

    expect($body)
        ->toContain('background-color: #402A1B')  // coffee button, --primary-fill
        ->toContain('#ECBB12')                    // brand yellow on the panel edge
        // Laravel's stock zinc palette must not survive anywhere.
        ->not->toContain('#18181b')
        ->not->toContain('#52525b')
        ->not->toContain('notification-logo');
});

test('the invitation welcomes rather than warning about a password', function () {
    $user = User::factory()->clientOwner()->create(['name' => 'María García']);

    $body = (string) (new ClientInvitation('tok3n', $user->client))->toMail($user)->render();

    // The recipient never asked for anything and has no password to reset.
    expect($body)->toContain('Creamos tu cuenta')
        ->and($body)->toContain('Lo que vas a encontrar dentro')
        ->and($body)->not->toContain('Recibimos una solicitud');
});

test('the staff invitation says back-office and names no brand', function () {
    // Staff have client_id null; the mail must not imply a client portal.
    $user = User::factory()->admin()->create(['name' => 'Ada Admin']);

    $mail = (new StaffInvitation('tok3n'))->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toContain('back-office')
        ->and($body)->toContain('Ada Admin')
        ->and($body)->toContain('Te dimos de alta en el equipo')
        ->and($body)->toContain('Crear mi contraseña');
});

test('both invitations share one template so the welcome cannot drift', function () {
    $owner = User::factory()->clientOwner()->create();
    $staff = User::factory()->equipo()->create();

    $client = (string) (new ClientInvitation('tok3n', $owner->client))->toMail($owner)->render();
    $breakfast = (string) (new StaffInvitation('tok3n'))->toMail($staff)->render();

    foreach ([$client, $breakfast] as $body) {
        expect($body)->toContain('Crear mi contraseña')
            ->toContain('Lo que vas a encontrar dentro')
            // Worded, not a raw minute count: config holds 10080.
            ->toContain('El enlace vence en 1 semana');
    }
});

test('the expiry is worded for a human, never as a minute count', function () {
    $user = User::factory()->clientOwner()->create();

    $body = (string) (new ClientInvitation('tok3n', $user->client))->toMail($user)->render();

    // "vence en 10080 minutos" is the failure this guards against.
    expect($body)->not->toContain('10080')
        ->and($body)->not->toContain('minutos');
});

test('re-inviting the same person twice is not swallowed by the reset throttle', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();

    $this->actingAs($admin)->post(route('admin.clients.users.store', $client), [
        'name' => 'Repetida',
        'email' => 'repetida@lamarca.test',
        'role' => UserRole::ClienteMiembro->value,
    ]);

    $user = User::firstWhere('email', 'repetida@lamarca.test');

    // Password::sendResetLink() refuses a second link inside the throttle
    // window and would report failure to an admin who just clicked "reenviar".
    $this->actingAs($admin)
        ->post(route('admin.clients.users.resend', [$client, $user]))
        ->assertSessionHas('status', "Enlace reenviado a {$user->email}.");

    Notification::assertSentToTimes($user, ClientInvitation::class, 2);
});
