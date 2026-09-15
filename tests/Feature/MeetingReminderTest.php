<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Enums\ReminderWindow;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\MeetingReminder;
use App\Models\User;
use App\Notifications\MeetingReminder as MeetingReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The three reminders a meeting is owed — SEG-01 of the beta review.
 *
 * ⚠️ MOST OF THESE ARE ABOUT AN UNRELIABLE CRON, not about arithmetic. The
 * scheduler on this host may not exist, may run late, and may run twice
 * (CLAUDE.md §3), so the command is written to be safe under all three — and
 * that is the part worth pinning, because it is invisible when it works and
 * embarrassing when it does not.
 */
beforeEach(function () {
    Notification::fake();

    // A Wednesday, so "the Monday of the meeting's week" is unambiguous and
    // distinct from every other window.
    Carbon::setTestNow(Carbon::parse('2026-09-02 09:00:00'));

    $this->client = Client::factory()->create(['name' => 'Alea']);

    $this->owner = User::factory()->clientOwner($this->client->id, [PortalSection::Reuniones->value => AccessLevel::Read->value])->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Through the relation: client_id is not fillable on Meeting. */
function aMeetingAt(Client $client, string $when): Meeting
{
    return $client->meetings()->create([
        'title' => 'Revisión de territorio',
        'scheduled_at' => Carbon::parse($when),
    ]);
}

/* --- when each window fires ---------------------------------------------- */

test('the three windows land where the report says', function () {
    // Friday 11 September, 15:00.
    $meeting = Carbon::parse('2026-09-11 15:00:00');

    expect(ReminderWindow::Semana->firesAt($meeting)->toDateTimeString())
        // The Monday of that week, in the morning.
        ->toBe('2026-09-07 08:00:00')
        ->and(ReminderWindow::Dia->firesAt($meeting)->toDateTimeString())
        ->toBe('2026-09-10 15:00:00')
        ->and(ReminderWindow::Hora->firesAt($meeting)->toDateTimeString())
        ->toBe('2026-09-11 14:00:00');
});

test('a Monday meeting is announced the Friday before, not the same morning', function () {
    // ⚠️ The corner the report itself named. "El lunes de la semana" would put
    // this notice hours before the meeting, after a weekend nobody could use.
    $monday = Carbon::parse('2026-09-07 10:00:00');

    expect($monday->isMonday())->toBeTrue()
        ->and(ReminderWindow::Semana->firesAt($monday)->toDateTimeString())
        ->toBe('2026-09-04 08:00:00')
        ->and(ReminderWindow::Semana->firesAt($monday)->isFriday())->toBeTrue();
});

/* --- sending -------------------------------------------------------------- */

test('a meeting tomorrow sends the day reminder and logs it', function () {
    $meeting = aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentTo($this->owner, MeetingReminderNotification::class,
        fn ($notification) => $notification->window === ReminderWindow::Dia);

    expect($meeting->reminders()->count())->toBe(1)
        ->and($meeting->reminders()->first()->window)->toBe(ReminderWindow::Dia);
});

test('the notice reaches Breakfast as well as the brand', function () {
    // "Enviar recordatorios al cliente y al equipo de Breakfast."
    $staff = User::factory()->equipo()->create();
    $this->client->staff()->attach($staff);

    aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentTo($this->owner, MeetingReminderNotification::class);
    Notification::assertSentTo($staff, MeetingReminderNotification::class);
});

test('a notice goes to the portal and to mail, and names the brand', function () {
    $meeting = aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentTo($this->owner, MeetingReminderNotification::class,
        function ($notification, array $channels) use ($meeting) {
            $mail = $notification->toMail($this->owner);
            $row = $notification->toArray($this->owner);

            return in_array('mail', $channels, true)
                && in_array('database', $channels, true)
                // The brand is in the subject: the same address may be on
                // several, and "reunión mañana" alone is a question.
                && str_contains($mail->subject, 'Alea')
                && $row['brand'] === 'Alea'
                && $row['meeting_id'] === $meeting->id;
        });
});

/* --- and the cron ---------------------------------------------------------- */

test('running twice does not tell anybody twice', function () {
    aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 1);
});

test('a window that fired minutes ago is still sent', function () {
    // The boundary from the useful side. The meeting is at 09:20; the hour
    // notice was due at 08:20 and its grace runs to 09:20, so at 09:19 it is
    // late but still true — and a reminder an hour late is better than none.
    aMeetingAt($this->client, '2026-09-02 09:20:00');

    Carbon::setTestNow(Carbon::parse('2026-09-02 09:19:00'));

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentTo($this->owner, MeetingReminderNotification::class,
        fn ($notification) => $notification->window === ReminderWindow::Hora);
});

test('a stale notice is skipped when the cron has been down for hours', function () {
    $meeting = aMeetingAt($this->client, '2026-09-04 10:00:00');

    // ⚠️ THE CASE THE GRACE PERIOD EXISTS FOR. Nothing ran for two days, and
    // now it is 15 minutes before the meeting. The DAY notice was due on
    // 2026-09-03 at 10:00 and expired 12 hours later — sending it now would
    // tell somebody "mañana tienes reunión" about a meeting starting in
    // fifteen minutes. The HOUR notice is still true and does go out.
    Carbon::setTestNow(Carbon::parse('2026-09-04 09:45:00'));

    $this->artisan('meetings:remind')->assertSuccessful();

    $windows = $meeting->reminders()->pluck('window')->all();

    expect($windows)->toContain(ReminderWindow::Hora)
        // Expired on 2026-09-03 at 22:00, well before now.
        ->and($windows)->not->toContain(ReminderWindow::Dia);
});

test('a cancelled meeting reminds nobody', function () {
    $meeting = aMeetingAt($this->client, '2026-09-03 08:00:00');

    // Assigned, not mass-updated: cancelled_at is deliberately outside
    // $fillable so it can only be set on purpose. See MeetingController::cancel.
    $meeting->cancelled_at = now();
    $meeting->save();

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(MeetingReminder::count())->toBe(0);
});

test('a meeting that already started reminds nobody', function () {
    aMeetingAt($this->client, '2026-09-02 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

/* --- moving --------------------------------------------------------------- */

test('moving a meeting re-arms the reminders that have not gone out', function () {
    $meeting = aMeetingAt($this->client, '2026-09-03 08:00:00');

    // The day-before notice goes out.
    $this->artisan('meetings:remind')->assertSuccessful();
    expect($meeting->fresh()->reminders()->count())->toBe(1);

    // It moves a fortnight out. The rows for the old date must not suppress the
    // new date's windows — otherwise everybody was told about the wrong day and
    // then never reminded about the right one.
    $meeting->update(['scheduled_at' => Carbon::parse('2026-09-17 10:00:00')]);
    $meeting->fresh()->rearmReminders();

    expect($meeting->fresh()->reminders()->count())->toBe(0);

    // And the new date's day-before notice fires when it comes round.
    Carbon::setTestNow(Carbon::parse('2026-09-16 10:30:00'));

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 2);
});

test('a reminder already sent is not re-sent when the meeting moves', function () {
    $meeting = aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    // Moved by ten minutes. The "mañana" notice was true when it went out and
    // cannot be unsent, so clearing it would only send a second one.
    $meeting->update(['scheduled_at' => Carbon::parse('2026-09-03 08:10:00')]);
    $meeting->fresh()->rearmReminders();

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 1);
});

/* --- scope ---------------------------------------------------------------- */

test('somebody without Reuniones is not reminded about them', function () {
    $stranger = User::factory()->clientOwner($this->client->id, [])->create();

    aMeetingAt($this->client, '2026-09-03 08:00:00');

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertNotSentTo($stranger, MeetingReminderNotification::class);
});
