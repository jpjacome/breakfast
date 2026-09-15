<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/**
 * The six screens you pass through before you are inside.
 *
 * WHY THIS FILE EXISTS. Four of them had no test of any kind, and it showed:
 * until 2026-08-18 five of the six were still written against a design system
 * that was never built — .bkf-input, .alert--success, var(--space-5), none of
 * which existed in any stylesheet — on a layout no CSS file mentioned. They
 * rendered as raw browser HTML. /reset-password is where the invitation mail
 * lands, so the first page a client's user ever opened was the unpainted one,
 * arriving from a mail that is carefully set in the brand.
 *
 * What is pinned here is what a test can actually see: that each screen opens,
 * that it carries the entrance markup the shared stylesheet keys on, and that
 * the stylesheet is a file Vite is told to build. A missing rule is invisible
 * to Pest; a screen wearing markup nothing styles is not.
 */

/** The class auth.css hangs everything on. No .auth, no paint. */
function assertWearsTheEntrance(TestResponse $response): void
{
    $response->assertOk()->assertSee('class="auth', escape: false);
}

test('the login screen opens', function () {
    assertWearsTheEntrance($this->get(route('login')));
});

test('the forgotten-password screen opens', function () {
    assertWearsTheEntrance($this->get(route('password.request')));
});

test('the choose-a-password screen opens, which is where the invitation lands', function () {
    $user = User::factory()->clientOwner(Client::factory()->create())->create();

    assertWearsTheEntrance($this->get(route('password.reset', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
    ])));
});

test('the two-factor challenge opens', function () {
    // Fortify only serves it mid-login, so the session has to say so.
    $user = User::factory()->admin()->create();

    assertWearsTheEntrance(
        $this->withSession(['login.id' => $user->id, 'login.remember' => false])
            ->get(route('two-factor.login'))
    );
});

test('the confirm-password screen opens', function () {
    $user = User::factory()->admin()->create();

    assertWearsTheEntrance($this->actingAs($user)->get(route('password.confirm')));
});

test('every entrance screen is a stylesheet Vite is told to build', function () {
    // The other half of the same bug: a page may name a file that does not
    // exist, and nothing at runtime complains until somebody looks at it.
    $config = file_get_contents(base_path('vite.config.js'));

    foreach (['auth', 'login'] as $sheet) {
        expect(file_exists(resource_path("css/{$sheet}.css")))->toBeTrue()
            ->and($config)->toContain("resources/css/{$sheet}.css");
    }
});
