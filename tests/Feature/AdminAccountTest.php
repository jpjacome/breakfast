<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('a staff member reaches their own account screen', function () {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada Lovelace']));

    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee('Mi cuenta')
        ->assertSee('Ada Lovelace');
});

test('a client user cannot reach the admin account screen', function () {
    $this->actingAs(User::factory()->clientOwner(Client::factory()->create())->create());

    // 404 rather than 403 — see EnsureUserIsBreakfast.
    $this->get(route('admin.account'))->assertNotFound();
});

test('a staff member changes their own name', function () {
    $user = User::factory()->admin()->create(['name' => 'Ada']);
    $this->actingAs($user);

    $this->put(route('user-profile-information.update'), ['name' => 'Ada L.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Ada L.');
});

test('nobody changes their own address, posted or not', function () {
    $user = User::factory()->admin()->create(['name' => 'Ada', 'email' => 'ada@vamosdebreakfast.test']);
    $this->actingAs($user);

    // The form does not offer the field; this is the crafted request that
    // would slip past a rule enforced only in the markup.
    $this->put(route('user-profile-information.update'), [
        'name' => 'Ada L.',
        'email' => 'otra@vamosdebreakfast.test',
    ])->assertRedirect();

    expect($user->fresh())
        ->name->toBe('Ada L.')
        ->email->toBe('ada@vamosdebreakfast.test');
});

test('the account screen shows the address without offering to edit it', function () {
    $this->actingAs(User::factory()->admin()->create(['email' => 'ada@vamosdebreakfast.test']));

    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee('ada@vamosdebreakfast.test')
        ->assertDontSee('name="email"', escape: false);
});

test('a staff member changes their own password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->put(route('user-password.update'), [
        'current_password' => 'la-de-siempre',
        'password' => 'una-mucho-mejor',
        'password_confirmation' => 'una-mucho-mejor',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Hash::check('una-mucho-mejor', $user->fresh()->password))->toBeTrue();
});

test('the wrong current password does not change the password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->put(route('user-password.update'), [
        'current_password' => 'no-es-esta',
        'password' => 'una-mucho-mejor',
        'password_confirmation' => 'una-mucho-mejor',
    ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

    expect(Hash::check('la-de-siempre', $user->fresh()->password))->toBeTrue();
});

/* -----------------------------------------------------------------------------
 | Two-step verification
 |
 | The whole point of the inline password field is that these four writes need
 | no separate confirm-password screen — so each one is checked both ways.
 ---------------------------------------------------------------------------- */

test('turning on two-step needs the current password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'no-es-esta'])
        ->assertSessionHasErrors('current_password', errorBag: 'enableTwoFactor');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a secret is not a second factor until a code confirms it', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'la-de-siempre'])
        ->assertRedirect();

    $user->refresh();

    // Secret issued, nothing armed: this is the state the QR panel draws.
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    // The half-finished state draws its own panel — QR, hand-typed key, and
    // the box for the six digits.
    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee('two-factor-qr')
        ->assertSee('Confirmar y activar');

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

    $this->post(route('admin.account.two-factor.confirm'), [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    // Shown once, on the way past — never read back out of the record.
    expect(session('recovery_codes'))->toBeArray()->toHaveCount(8);

    // The codes were flashed, so the load right after confirming is the one
    // reveal — and the load after that has nothing left to show.
    $this->get(route('admin.account'))
        ->assertOk()
        ->assertSee('Activada')
        ->assertSee('Códigos de recuperación')
        ->assertSee('Desactivar la verificación en dos pasos');

    $this->get(route('admin.account'))
        ->assertOk()
        ->assertDontSee('Códigos de recuperación');
});

test('a wrong code leaves two-step unconfirmed', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'la-de-siempre']);

    $this->post(route('admin.account.two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('an unconfirmed setup is abandoned without a password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'la-de-siempre']);
    $this->delete(route('admin.account.two-factor.disable'))->assertRedirect();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('turning off a live second factor needs the current password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'la-de-siempre']);
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $this->post(route('admin.account.two-factor.confirm'), [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ]);

    $this->delete(route('admin.account.two-factor.disable'), ['current_password' => 'no-es-esta'])
        ->assertSessionHasErrors('current_password', errorBag: 'disableTwoFactor');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->delete(route('admin.account.two-factor.disable'), ['current_password' => 'la-de-siempre'])
        ->assertRedirect();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('reissuing the recovery codes replaces them and needs the password', function () {
    $user = User::factory()->admin()->create(['password' => Hash::make('la-de-siempre')]);
    $this->actingAs($user);

    $this->post(route('admin.account.two-factor.enable'), ['current_password' => 'la-de-siempre']);
    $before = $user->fresh()->recoveryCodes();

    $this->post(route('admin.account.two-factor.recovery-codes'), ['current_password' => 'no-es-esta'])
        ->assertSessionHasErrors('current_password', errorBag: 'regenerateRecoveryCodes');

    expect($user->fresh()->recoveryCodes())->toBe($before);

    $this->post(route('admin.account.two-factor.recovery-codes'), ['current_password' => 'la-de-siempre'])
        ->assertRedirect();

    expect($user->fresh()->recoveryCodes())->not->toBe($before)->toHaveCount(8);
});
