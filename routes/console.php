<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| NOTE: none of this runs unless a cron entry on the host calls the scheduler
| every minute. On iFastNet that is a cron job in cPanel:
|
|   * * * * * cd /home/<user>/portal && php artisan schedule:run >/dev/null 2>&1
*/

/*
 * Delete password-reset and invitation tokens that have already expired.
 *
 * Expiry alone only makes a token stop working; the row stays in
 * password_reset_tokens forever, and rides along in every database backup.
 * With a seven-day window (see config/auth.php) that is a week's worth of
 * unclaimed invitations sitting there as live keys, so they get swept.
 *
 * Daily is enough: the point is that they do not accumulate for years, not
 * that they vanish the minute they lapse.
 */
Schedule::command('auth:clear-resets')->daily();

/*
 * The three reminders each meeting is owed: the Monday of its week, the day
 * before, and the hour before. SEG-01 of the beta review.
 *
 * Every fifteen minutes because the tightest window is one hour and a coarser
 * tick would drift it noticeably — a "one hour before" notice arriving with
 * forty minutes to go is a different message.
 *
 * ⚠️ THE COMMAND DOES NOT DEPEND ON THIS RUNNING ON TIME, or at all. It sends
 * windows whose moment has already passed and skips the ones that passed too
 * long ago, so a cron that stops for a day resumes correctly rather than
 * flooding people with stale notices. See SendMeetingReminders — and note this
 * is the first thing in the app that actually wants the cron to exist, so it is
 * worth confirming in cPanel rather than assuming.
 */
Schedule::command('meetings:remind')->everyFifteenMinutes();
