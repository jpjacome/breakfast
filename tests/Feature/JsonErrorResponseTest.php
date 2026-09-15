<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * What a failure looks like to JavaScript.
 *
 * WHY THIS FILE EXISTS. `shouldRenderJsonWhen` was narrowed to `api/*`, and
 * this app has no `api/*` — every JavaScript-driven endpoint is under
 * `admin/` or `portal/`. So a failure on one of them answered a `fetch` with
 * Laravel's HTML error page: `response.json()` threw, the panel fell back to
 * whatever string it had, and the real status never reached the screen.
 *
 * On 2026-09-15 a client sat in front of three "No obtuve respuesta." in a
 * row. Their eight-hour session had expired, every question was coming back
 * 419 wearing the "Page Expired" page, and because the request died at the
 * CSRF middleware there was nothing in laravel.log, nothing in ai_usage_logs
 * and no row in assistant_messages. Four silent logs, one unreadable
 * response, and a whole day spent looking for a bug in the assistant.
 *
 * FormRequests were never the gap — AdminAssistantRequest and the others
 * already override failedValidation. The gap was every OTHER failure: 419,
 * 404, 429, 500. Those come from the framework, not from a request class, and
 * they are exactly the ones a person cannot act on without being told.
 */

/** A brand and a member who was granted nothing — so a section 404s. */
function memberWithoutSections(): User
{
    $client = Client::factory()->create();

    return User::factory()->clientMember($client, [])->create();
}

it('answers a caller that asked for JSON with JSON, not an HTML error page', function () {
    // 404 rather than 403 is deliberate — see CLAUDE.md §6. What is pinned
    // here is the CONTENT TYPE, not the status: HTML here is what the browser
    // cannot parse, whatever the number attached to it.
    actingAs(memberWithoutSections())
        ->getJson(route('portal.reuniones'))
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

it('still shows a person the HTML error screen when they navigate to it', function () {
    // The other half, and the reason this is expectsJson() rather than a blanket
    // always-JSON: somebody typing a URL should get the page, not a JSON blob.
    $response = actingAs(memberWithoutSections())->get(route('portal.reuniones'));

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->toContain('text/html');
});

it('answers an admin fetch with JSON too', function () {
    // Both sides have JavaScript panels, and the dashboard assistant is under
    // admin/ — the same blind spot, and the one that would be hit by staff.
    $staff = User::factory()->create(['role' => UserRole::Equipo]);

    actingAs($staff)
        ->getJson('/admin/clientes/no-existe')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});
