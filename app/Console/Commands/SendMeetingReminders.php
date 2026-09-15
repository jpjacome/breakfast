<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\NotifyAboutMeeting;
use App\Enums\ReminderWindow;
use App\Models\Meeting;
use App\Models\MeetingReminder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Sends the three reminders each meeting is owed — SEG-01 of the beta review.
 *
 * ⚠️ WRITTEN FOR A CRON THAT MAY NOT BE THERE. CLAUDE.md §3 is explicit that the
 * cPanel scheduler may or may not exist, may run late, and may run twice; §13
 * says correctness must never depend on a scheduled job having run. So this
 * command does not fire windows AT their moment — it looks for windows whose
 * moment has PASSED and which have no row saying they went out. Run it once a
 * day, every fifteen minutes, or twice in the same second: the result is the
 * same set of reminders sent exactly once.
 *
 * Three rules do all the work:
 *
 *   1. The window's time has passed.               (otherwise it is not due yet)
 *   2. It has not passed by more than its grace.   (otherwise the notice is a lie)
 *   3. No row exists for it.                       (otherwise it already went)
 *
 * Rule 2 is the one that is easy to leave out and expensive to leave out. A cron
 * that was down since yesterday would otherwise announce "tu reunión es en una
 * hora" about a meeting that finished this morning — see
 * ReminderWindow::graceHours().
 *
 * ⚠️ THE ROW IS WRITTEN BEFORE THE MAIL GOES OUT. If the send half-fails, the
 * failure is reported and logged and the window is NOT retried. That is the
 * right way round for this: sending nothing is a missed reminder, while sending
 * twice is a client being emailed twice about the same meeting, and a
 * concurrent run — two crons, or somebody running this by hand while the cron
 * fires — must not be able to do the second. The unique index on
 * (meeting_id, window) is what actually enforces it.
 */
class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:remind';

    protected $description = 'Avisa de las reuniones próximas: la semana, el día y la hora antes.';

    public function handle(NotifyAboutMeeting $notifier): int
    {
        $meetings = Meeting::query()
            ->whereNull('cancelled_at')
            // Still ahead. A meeting that has started needs no warning, and
            // this is also what keeps the query small forever — the table grows
            // but the scan does not.
            ->where('scheduled_at', '>', now())
            // Nothing is due more than a week out: the earliest window is the
            // Monday of the meeting's own week, and the Friday before it when
            // the meeting is on a Monday.
            ->where('scheduled_at', '<=', now()->addDays(10))
            ->with(['client.staff', 'createdBy', 'reminders'])
            ->get();

        $sent = 0;

        foreach ($meetings as $meeting) {
            foreach (ReminderWindow::cases() as $window) {
                if (! $this->isDue($meeting, $window)) {
                    continue;
                }

                if (! $this->claim($meeting, $window)) {
                    continue;
                }

                $result = $notifier->remind($meeting, $window);

                MeetingReminder::query()
                    ->where('meeting_id', $meeting->id)
                    ->where('window', $window->value)
                    ->update(['recipients' => $result['sent']]);

                $sent++;

                $this->line(sprintf(
                    '%s · %s · %s → %d avisos%s',
                    $meeting->client->name,
                    $meeting->title,
                    $window->value,
                    $result['sent'],
                    $result['failed'] > 0 ? " ({$result['failed']} fallaron)" : '',
                ));
            }
        }

        $this->info($sent === 0
            ? 'No había recordatorios pendientes.'
            : "Se enviaron {$sent} recordatorio(s).");

        return self::SUCCESS;
    }

    /**
     * Has this window's moment passed, recently enough to still mean something?
     *
     * Reads the loaded relation rather than querying: the meetings were fetched
     * with their reminders, so a hundred meetings is one query and not four
     * hundred.
     */
    private function isDue(Meeting $meeting, ReminderWindow $window): bool
    {
        $firesAt = $window->firesAt($meeting->scheduled_at);

        if ($firesAt->isFuture()) {
            return false;
        }

        // Too late to be true — see the class docblock.
        if ($firesAt->addHours($window->graceHours())->isPast()) {
            return false;
        }

        return ! $meeting->reminders->contains(
            fn (MeetingReminder $reminder): bool => $reminder->window === $window
        );
    }

    /**
     * Take this window, or discover that something else already has.
     *
     * The insert IS the lock. Two processes reaching the same window race to
     * this line and the unique index decides: one gets a row, the other gets a
     * constraint violation and moves on having sent nothing. Checking first and
     * inserting after would leave the gap between them wide open, which on a
     * host where the cron overlaps its own run is not a theoretical gap.
     */
    private function claim(Meeting $meeting, ReminderWindow $window): bool
    {
        try {
            MeetingReminder::create([
                'meeting_id' => $meeting->id,
                'window' => $window->value,
                'sent_at' => now(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}
