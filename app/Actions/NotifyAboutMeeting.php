<?php

namespace App\Actions;

use App\Enums\ReminderWindow;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminder as MeetingReminderNotification;
use App\Notifications\MeetingScheduled;
use Illuminate\Support\Collection;

/**
 * Tells a brand about one of its meetings, on the channels the admin picked.
 *
 * THE ONLY PLACE THAT SENDS A MEETING NOTIFICATION. Creating, moving and
 * cancelling all come through here, so "who hears about it" is decided once.
 *
 * TWO KINDS OF MESSAGE, one gatekeeper. handle() announces a CHANGE — created,
 * moved, cancelled — on the channels an admin ticked. remind() announces that a
 * meeting is close, on both channels and to a wider list. Different messages,
 * one answer to "who hears about this meeting".
 *
 * ⚠️ Sends SYNCHRONOUSLY. Nothing drains the queue on this host, so a queued
 * notification is one that never happens — same reasoning as
 * InviteUserToClient::sendSetupLink(), and the same failure handling: this
 * never throws. A meeting that was saved and could not be announced is still
 * saved, and the screen says so rather than losing the meeting to a mail
 * server being down.
 */
class NotifyAboutMeeting
{
    /**
     * @param  array<int, string>  $channels  'database' and/or 'mail'.
     * @return array{sent: int, failed: int}
     */
    public function handle(Meeting $meeting, string $event, array $channels): array
    {
        $channels = array_values(array_intersect($channels, ['database', 'mail']));

        if ($channels === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;

        foreach ($meeting->client->meetingAudience() as $user) {
            // Per user, not per batch: one bad address must not cost everybody
            // else their notification.
            try {
                $user->notify(new MeetingScheduled($meeting, $event, $channels));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send one of a meeting's three reminders — SEG-01 of the beta review.
     *
     * ⚠️ A WIDER AUDIENCE THAN handle(), and deliberately so. The report asks
     * for "recordatorios al cliente y al equipo de Breakfast": a change to a
     * meeting is news for the brand, but a reminder is for everybody who has to
     * be in the room. So this is the brand's people PLUS the staff assigned to
     * that brand PLUS whoever booked it.
     *
     * NOT every admin. Admins reach every brand by role (User::coversEveryBrand),
     * so including them would mail the whole of Breakfast three times per
     * meeting per brand — which is how people learn to filter these into a
     * folder they never open.
     *
     * Never throws, like handle(): a reminder that could not be sent must not
     * take down the scheduled command mid-run and leave the rest of the day's
     * meetings unannounced.
     *
     * @return array{sent: int, failed: int}
     */
    public function remind(Meeting $meeting, ReminderWindow $window): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->reminderAudience($meeting) as $user) {
            try {
                $user->notify(new MeetingReminderNotification($meeting, $window));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Everyone who should be reminded, each of them once.
     *
     * Keyed by id while collecting: the person who booked the meeting is
     * usually also on the brand's staff, and being on two lists is not a reason
     * to get two emails.
     *
     * @return Collection<int, User>
     */
    private function reminderAudience(Meeting $meeting): Collection
    {
        $people = [];

        foreach ($meeting->client->meetingAudience() as $user) {
            $people[$user->id] = $user;
        }

        foreach ($meeting->client->staff as $user) {
            $people[$user->id] ??= $user;
        }

        if ($meeting->createdBy !== null) {
            $people[$meeting->createdBy->id] ??= $meeting->createdBy;
        }

        return collect(array_values($people));
    }

    /**
     * The sentence to flash after saving, so the admin knows what actually
     * left the building rather than assuming it did.
     *
     * @param  array{sent: int, failed: int}  $result
     */
    public function summarise(array $result, array $channels): string
    {
        if ($result['sent'] === 0 && $result['failed'] === 0) {
            return 'No se avisó a nadie.';
        }

        $how = match (true) {
            in_array('mail', $channels, true) && in_array('database', $channels, true) => 'por correo y en el portal',
            in_array('mail', $channels, true) => 'por correo',
            default => 'en el portal',
        };

        $sentence = $result['sent'] === 1
            ? "Se avisó a 1 persona {$how}."
            : "Se avisó a {$result['sent']} personas {$how}.";

        if ($result['failed'] > 0) {
            $sentence .= " {$result['failed']} aviso(s) no salieron: la reunión quedó guardada igual.";
        }

        return $sentence;
    }

    /** True when this brand has nobody who can see Reuniones. */
    public function audienceIsEmpty(Meeting $meeting): bool
    {
        return $meeting->client->meetingAudience()->isEmpty();
    }

    /** @return array<int, User> */
    public function audienceFor(Meeting $meeting): array
    {
        return $meeting->client->meetingAudience()->all();
    }
}
