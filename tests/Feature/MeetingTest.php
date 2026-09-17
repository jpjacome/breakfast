<?php

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingScheduled;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->client = Client::factory()->create(['name' => 'Cafetería Norte']);
    // The owner is granted Reuniones explicitly: owners get Equipo and
    // Suscripción by role, but every grantable section still comes from their
    // permissions map. An owner with an empty map sees no meetings.
    $this->owner = User::factory()->clientOwner($this->client->id, [PortalSection::Reuniones->value => AccessLevel::Read->value])->create();
});

/** The payload the admin form posts. */
function meetingPayload(array $overrides = []): array
{
    return [
        'title' => 'Revisión de territorio',
        'scheduled_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'link' => 'https://meet.google.com/abc-defg-hij',
        'agenda' => 'Revisamos el territorio y el look and feel.',
        'notify_portal' => '1',
        'notify_mail' => '1',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Scheduling
|--------------------------------------------------------------------------
*/

test('an admin schedules a meeting', function () {
    Notification::fake();

    // back(), not a fixed screen: the same form is on the brand page and on
    // /admin/reuniones, and landing somewhere else after a click is a screen
    // you stop trusting.
    actingAs($this->admin)
        ->from(route('admin.clients.show', $this->client))
        ->post(route('admin.clients.meetings.store', $this->client), meetingPayload())
        ->assertRedirect(route('admin.clients.show', $this->client));

    $meeting = $this->client->meetings()->firstOrFail();

    expect($meeting->title)->toBe('Revisión de territorio')
        ->and($meeting->isUpcoming())->toBeTrue()
        ->and($meeting->createdBy->is($this->admin))->toBeTrue();
});

test('client users cannot schedule anything', function () {
    // 404 rather than 403 — the admin area does not exist for them.
    actingAs($this->owner)
        ->post(route('admin.clients.meetings.store', $this->client), meetingPayload())
        ->assertNotFound();
});

test('a link has to be a real address', function () {
    actingAs($this->admin)
        ->post(route('admin.clients.meetings.store', $this->client), meetingPayload(['link' => 'meet.google.com']))
        ->assertSessionHasErrors('link');

    expect($this->client->meetings()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Who hears about it
|--------------------------------------------------------------------------
*/

test('the brand is told on both channels when both are ticked', function () {
    Notification::fake();

    actingAs($this->admin)->post(route('admin.clients.meetings.store', $this->client), meetingPayload());

    Notification::assertSentTo($this->owner, MeetingScheduled::class,
        fn (MeetingScheduled $n) => $n->channels === ['database', 'mail']
            && $n->event === MeetingScheduled::CREATED);
});

test('unticking both boxes tells nobody', function () {
    Notification::fake();

    actingAs($this->admin)->post(
        route('admin.clients.meetings.store', $this->client),
        meetingPayload(['notify_portal' => null, 'notify_mail' => null]),
    );

    // An unchecked box posts nothing, and absence has to read as "no" rather
    // than "unspecified, use the default" — otherwise fixing a typo mails
    // the whole brand.
    Notification::assertNothingSent();
    expect($this->client->meetings()->count())->toBe(1);
});

test('only the portal is used when mail is unticked', function () {
    Notification::fake();

    actingAs($this->admin)->post(
        route('admin.clients.meetings.store', $this->client),
        meetingPayload(['notify_mail' => null]),
    );

    Notification::assertSentTo($this->owner, MeetingScheduled::class,
        fn (MeetingScheduled $n) => $n->channels === ['database']);
});

test('somebody without access to Reuniones is not told', function () {
    Notification::fake();

    $member = User::factory()->clientMember($this->client->id, [PortalSection::Estrategia->value => AccessLevel::Read->value])->create();

    actingAs($this->admin)->post(route('admin.clients.meetings.store', $this->client), meetingPayload());

    // Mailing them about a meeting in a section they cannot open would be
    // telling them about a page that 404s for them.
    Notification::assertNotSentTo($member, MeetingScheduled::class);
    Notification::assertSentTo($this->owner, MeetingScheduled::class);
});

test('another brand is never told', function () {
    Notification::fake();

    $stranger = User::factory()->clientOwner(Client::factory()->create()->id, [PortalSection::Reuniones->value => AccessLevel::Read->value])->create();

    actingAs($this->admin)->post(route('admin.clients.meetings.store', $this->client), meetingPayload());

    Notification::assertNotSentTo($stranger, MeetingScheduled::class);
});

test('a portal notification lands in the inbox', function () {
    actingAs($this->admin)->post(
        route('admin.clients.meetings.store', $this->client),
        meetingPayload(['notify_mail' => null]),
    );

    $note = $this->owner->fresh()->unreadNotifications->first();

    expect($note)->not->toBeNull()
        ->and($note->data['headline'])->toContain('Revisión de territorio')
        // The MEETING, not the list - item 11, 2026-09-17. The id was always
        // in the payload; the url pointed at the index beside it.
        ->and($note->data['url'])->toBe(route('portal.reunion', $note->data['meeting_id']));
});

/*
|--------------------------------------------------------------------------
| Moving and cancelling
|--------------------------------------------------------------------------
*/

test('changing only the agenda does not say the meeting moved', function () {
    Notification::fake();

    actingAs($this->admin)->post(route('admin.clients.meetings.store', $this->client), meetingPayload());
    $meeting = $this->client->meetings()->firstOrFail();

    actingAs($this->admin)->put(
        route('admin.clients.meetings.update', [$this->client, $meeting]),
        meetingPayload([
            'scheduled_at' => $meeting->scheduled_at->format('Y-m-d\TH:i'),
            'agenda' => 'Otra cosa.',
        ]),
    )->assertRedirect();

    Notification::assertSentTo($this->owner, MeetingScheduled::class,
        fn (MeetingScheduled $n) => $n->event !== MeetingScheduled::MOVED);
});

test('changing the date says the meeting moved', function () {
    Notification::fake();

    actingAs($this->admin)->post(route('admin.clients.meetings.store', $this->client), meetingPayload());
    $meeting = $this->client->meetings()->firstOrFail();

    actingAs($this->admin)->put(
        route('admin.clients.meetings.update', [$this->client, $meeting]),
        meetingPayload(['scheduled_at' => now()->addWeeks(2)->format('Y-m-d\TH:i')]),
    );

    Notification::assertSentTo($this->owner, MeetingScheduled::class,
        fn (MeetingScheduled $n) => $n->event === MeetingScheduled::MOVED);
});

test('cancelling keeps the meeting and takes it out of upcoming', function () {
    Notification::fake();

    $meeting = $this->client->meetings()->create([
        'title' => 'Se cae',
        'scheduled_at' => now()->addWeek(),
    ]);

    actingAs($this->admin)
        ->post(route('admin.clients.meetings.cancel', [$this->client, $meeting]))
        ->assertRedirect();

    // The row stays: the client was told this exists and may still look for it.
    expect($meeting->fresh()->isCancelled())->toBeTrue()
        ->and($this->client->nextMeeting())->toBeNull()
        ->and(Meeting::count())->toBe(1);

    Notification::assertSentTo($this->owner, MeetingScheduled::class,
        fn (MeetingScheduled $n) => $n->event === MeetingScheduled::CANCELLED);
});

test('deleting a meeting tells nobody', function () {
    Notification::fake();

    $meeting = $this->client->meetings()->create([
        'title' => 'Error de dedo',
        'scheduled_at' => now()->addWeek(),
    ]);

    actingAs($this->admin)
        ->delete(route('admin.clients.meetings.destroy', [$this->client, $meeting]))
        ->assertRedirect();

    expect(Meeting::count())->toBe(0);
    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| What the client sees
|--------------------------------------------------------------------------
*/

test('the next meeting is derived from the date, with no status to move', function () {
    $this->client->meetings()->create(['title' => 'Ya pasó', 'scheduled_at' => now()->subWeek()]);
    $soon = $this->client->meetings()->create(['title' => 'La próxima', 'scheduled_at' => now()->addDay()]);
    $this->client->meetings()->create(['title' => 'Más tarde', 'scheduled_at' => now()->addMonth()]);

    expect($this->client->nextMeeting()->is($soon))->toBeTrue();
});

test('the dashboard carries the next meeting', function () {
    $this->client->meetings()->create([
        'title' => 'Revisión de territorio',
        'scheduled_at' => now()->addDay(),
        'link' => 'https://meet.google.com/abc-defg-hij',
    ]);

    actingAs($this->owner)
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Próxima reunión')
        ->assertSee('Revisión de territorio')
        ->assertSee('https://meet.google.com/abc-defg-hij');
});

test('a member without Reuniones sees no meeting card', function () {
    $this->client->meetings()->create(['title' => 'Secreta', 'scheduled_at' => now()->addDay()]);

    $member = User::factory()->clientMember($this->client->id, [PortalSection::Estrategia->value => AccessLevel::Read->value])->create();

    // Showing the card would tell them their brand has a Reuniones section.
    actingAs($member)->get(route('portal.home'))->assertOk()->assertDontSee('Secreta');
});

test('the reuniones page needs the section', function () {
    $member = User::factory()->clientMember($this->client->id, [])->create();

    actingAs($member)->get(route('portal.reuniones'))->assertNotFound();
    actingAs($this->owner)->get(route('portal.reuniones'))->assertOk();
});

test('an empty agenda says so instead of rendering nothing', function () {
    actingAs($this->owner)
        ->get(route('portal.reuniones'))
        ->assertOk()
        ->assertSee('No hay ninguna reunión agendada');
});

test('the client cannot post to any meeting route', function () {
    $meeting = $this->client->meetings()->create([
        'title' => 'Mía', 'scheduled_at' => now()->addWeek(),
    ]);

    // Reuniones is read-only for the brand: there is no write path at all,
    // not one hidden behind a permission check.
    actingAs($this->owner)
        ->post(route('admin.clients.meetings.cancel', [$this->client, $meeting]))
        ->assertNotFound();

    expect($meeting->fresh()->isCancelled())->toBeFalse();
});

test('marking the inbox read empties the bell', function () {
    actingAs($this->admin)->post(
        route('admin.clients.meetings.store', $this->client),
        meetingPayload(['notify_mail' => null]),
    );

    expect($this->owner->fresh()->unreadNotifications)->toHaveCount(1);

    actingAs($this->owner)
        ->from(route('portal.home'))
        ->post(route('portal.notifications.read'))
        ->assertRedirect(route('portal.home'));

    expect($this->owner->fresh()->unreadNotifications)->toHaveCount(0);
});

/* -------------------------------------------------------------------------
 | The roster at /admin/reuniones
 |
 | The card on a brand page answers "when do we next see THIS brand". This
 | answers "what does the week look like", which before meant opening every
 | brand in turn.
 ------------------------------------------------------------------------- */

test('the roster lists every brand\'s meetings and names the brand', function () {
    $otra = Client::factory()->create(['name' => 'Panadería Sur']);
    $this->client->meetings()->create(meetingPayload());
    $otra->meetings()->create(['title' => 'Kickoff', 'scheduled_at' => now()->addDays(3)]);

    actingAs($this->admin)
        ->get(route('admin.meetings.index'))
        ->assertOk()
        ->assertSee('Revisión de territorio')
        ->assertSee('Kickoff')
        // Whose meeting it is is the first thing being looked for on a list
        // that crosses brands.
        ->assertSee($this->client->name)
        ->assertSee('Panadería Sur');
});

test('the roster is scoped to the brands the user is on', function () {
    $otra = Client::factory()->create(['name' => 'Panadería Sur']);
    $otra->meetings()->create(['title' => 'Secreta', 'scheduled_at' => now()->addDay()]);
    $this->client->meetings()->create(meetingPayload());

    $equipo = User::factory()->equipo()->create();
    $equipo->assignedClients()->attach($this->client);

    actingAs($equipo)
        ->get(route('admin.meetings.index'))
        ->assertOk()
        ->assertSee('Revisión de territorio')
        ->assertDontSee('Secreta');
});

test('the calendar draws the month asked for', function () {
    $this->client->meetings()->create([
        'title' => 'En septiembre',
        'scheduled_at' => '2026-09-15 11:00',
    ]);

    // ?mes=YYYY-MM rather than an offset, so a link to a month is still that
    // month when somebody opens it tomorrow.
    actingAs($this->admin)
        ->get(route('admin.meetings.index', ['mes' => '2026-09']))
        ->assertOk()
        ->assertSee('septiembre de 2026')
        ->assertSee('11:00');
});

test('a nonsense month shows a calendar rather than an error', function () {
    actingAs($this->admin)
        ->get(route('admin.meetings.index', ['mes' => 'no-es-un-mes']))
        ->assertOk();
});

test('the new-meeting screen asks for the brand first', function () {
    actingAs($this->admin)
        ->get(route('admin.meetings.create'))
        ->assertOk()
        ->assertSee('Elige una marca')
        // The rest of the form needs a brand, because the action is that
        // brand's own route.
        ->assertDontSee('name="scheduled_at"', escape: false);

    actingAs($this->admin)
        ->get(route('admin.meetings.create', ['marca' => $this->client->slug]))
        ->assertOk()
        ->assertSee('name="scheduled_at"', escape: false)
        ->assertSee(route('admin.clients.meetings.store', $this->client), escape: false);
});

test('scheduling from the roster lands back on the roster', function () {
    Notification::fake();

    actingAs($this->admin)
        ->from(route('admin.meetings.create', ['marca' => $this->client->slug]))
        ->post(route('admin.clients.meetings.store', $this->client), [
            ...meetingPayload(),
            'desde' => 'agenda',
        ])
        ->assertRedirect(route('admin.meetings.index'));
});

test('the brand page carries the meetings card, the process screen no longer does', function () {
    $this->client->meetings()->create(meetingPayload());

    actingAs($this->admin)
        ->get(route('admin.clients.show', $this->client))
        ->assertOk()
        ->assertSee('Revisión de territorio')
        ->assertSee('Agendar reunión');

    // The process screen is the three steps and the 48 entregables. A meeting
    // is neither.
    actingAs($this->admin)
        ->get(route('admin.clients.process.edit', $this->client))
        ->assertOk()
        ->assertDontSee('Agendar reunión');
});

test('a client user cannot reach the roster', function () {
    actingAs($this->owner)->get(route('admin.meetings.index'))->assertNotFound();
    actingAs($this->owner)->get(route('admin.meetings.create'))->assertNotFound();
});
