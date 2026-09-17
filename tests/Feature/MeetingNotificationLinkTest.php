<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\BrandRole;
use App\Enums\PortalSection;
use App\Models\Client;
use App\Models\User;
use App\Notifications\MeetingScheduled;

/**
 * Item 11 — a notification opens the RIGHT meeting.
 *
 * Notifications already carried `meeting_id`; what they pointed at was the
 * list. The half that makes this more than a link is the active brand: a
 * person in two brands would otherwise open a page listing whichever brand
 * they happened to be in, with the right URL and no error anywhere.
 */
function memberOf(Client $client, array $permissions = [PortalSection::Reuniones->value => 'read']): User
{
    $user = User::factory()->create(['role' => 'cliente_miembro']);

    $user->brands()->attach($client, [
        'role' => BrandRole::Miembro->value,
        'permissions' => json_encode($permissions),
    ]);

    return $user;
}

/** There is no MeetingFactory; meetings are made through the relation. */
function meetingOn(Client $client, string $title, mixed $when = null)
{
    return $client->meetings()->create([
        'title' => $title,
        'scheduled_at' => $when ?? now()->addWeek(),
    ]);
}

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Panadería Sur']);
    $this->meeting = meetingOn($this->client, 'Revisión de identidad');
});

it('points a notification at the meeting rather than the list', function () {
    $user = memberOf($this->client);

    $payload = (new MeetingScheduled($this->meeting))->toArray($user);

    expect($payload['url'])
        ->toBe(route('portal.reunion', $this->meeting))
        // The id was always in the payload; only the url was wrong.
        ->and($payload['meeting_id'])->toBe($this->meeting->id);
});

it('lands on the list with the meeting as the fragment', function () {
    $user = memberOf($this->client);

    $this->actingAs($user)
        ->get(route('portal.reunion', $this->meeting))
        ->assertRedirect(route('portal.reuniones').'#reunion-'.$this->meeting->id);
});

it('works for a meeting that already happened', function () {
    // The brief asks for this explicitly, and it costs nothing — the list
    // already renders past meetings, it was only the URL that could not name
    // one of them.
    $past = meetingOn($this->client, 'Kickoff', now()->subMonth());

    $this->actingAs(memberOf($this->client))
        ->get(route('portal.reunion', $past))
        ->assertRedirect(route('portal.reuniones').'#reunion-'.$past->id);
});

it('switches the active brand to the one the meeting belongs to', function () {
    /*
     * ⚠️ THE TEST THAT MATTERS. Without the switch this route is a fancy
     * redirect: the person lands on Reuniones still scoped to their other
     * brand, the fragment points at an id that is not on the page, and nothing
     * anywhere reports a problem.
     */
    $other = Client::factory()->create(['name' => 'Aurora']);
    $user = memberOf($this->client);

    $user->brands()->attach($other, [
        'role' => BrandRole::Miembro->value,
        'permissions' => json_encode([PortalSection::Reuniones->value => 'read']),
    ]);

    // Working in the other brand when the notification arrives.
    $this->actingAs($user)->withSession(['active_client_id' => $other->id])
        ->get(route('portal.reunion', $this->meeting))
        ->assertRedirect(route('portal.reuniones').'#reunion-'.$this->meeting->id)
        ->assertSessionHas('active_client_id', $this->client->id);

    // And the page it lands on really is the meeting's brand.
    $this->actingAs($user)->get(route('portal.reuniones'))
        ->assertSee('Revisión de identidad', false);
});

it('renders an anchor for every meeting on the page', function () {
    $past = meetingOn($this->client, 'Kickoff', now()->subWeek());

    $this->actingAs(memberOf($this->client))
        ->get(route('portal.reuniones'))
        ->assertSee('id="reunion-'.$this->meeting->id.'"', false)
        ->assertSee('id="reunion-'.$past->id.'"', false);
});

it('404s for a member who was never given Reuniones', function () {
    // 404, not 403: they must not learn the meeting exists (CLAUDE.md §6).
    $this->actingAs(memberOf($this->client, []))
        ->get(route('portal.reunion', $this->meeting))
        ->assertNotFound();
});

it('404s for somebody who is not in the brand at all', function () {
    $stranger = memberOf(Client::factory()->create());

    $this->actingAs($stranger)
        ->get(route('portal.reunion', $this->meeting))
        ->assertNotFound();
});

it('does not move a stranger into a brand by guessing an id', function () {
    /*
     * ⚠️ The access check and the brand switch are two separate refusals on
     * purpose. Even if the first were ever loosened, ActiveBrand::set() refuses
     * a brand that is not theirs — so a guessed meeting id cannot park somebody
     * inside somebody else's brand for every later request in the session.
     */
    $stranger = memberOf($other = Client::factory()->create());

    $this->actingAs($stranger)
        ->get(route('portal.reunion', $this->meeting))
        ->assertNotFound();

    expect(session('active_client_id'))->not->toBe($this->client->id);
    expect($stranger->fresh()->activeBrand()?->id)->toBe($other->id);
});

it('lets Breakfast staff through their own side, not this one', function () {
    // Staff carry no brands, so ActiveBrand::set() refuses and this 404s.
    // They reach every meeting through /admin/reuniones instead (CLAUDE.md §11).
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('portal.reunion', $this->meeting))
        ->assertNotFound();
});

it('keeps the level check, not just membership', function () {
    // A stored 'write' is clamped to read on the way out (CLAUDE.md §5), so
    // this is really asking that a level of any kind is present at all.
    $user = memberOf($this->client, [PortalSection::Reuniones->value => AccessLevel::Read->value]);

    $this->actingAs($user)
        ->get(route('portal.reunion', $this->meeting))
        ->assertRedirect(route('portal.reuniones').'#reunion-'.$this->meeting->id);
});
