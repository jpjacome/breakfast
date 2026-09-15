<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\NotifyAboutMeeting;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingRequest;
use App\Models\Client;
use App\Models\Meeting;
use App\Notifications\MeetingScheduled;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Meetings, from the Breakfast side. The client only ever reads them.
 *
 * TWO PLACES RENDER THESE and both post here: the card on a brand's page, and
 * the roster at /admin/reuniones that crosses every brand. Every action
 * answers with back(), so a cancel from the roster returns to the roster and
 * one from the brand page returns to the brand — a screen that throws you
 * somewhere else after a click is one you stop trusting.
 *
 * ⚠️ The write routes stay brand-scoped ({client} in the URL) even when the
 * form is on the roster. That is what 'covers-client' guards on, and moving
 * the brand into the request body would mean re-implementing the scope check
 * by hand in each method.
 */
class MeetingController extends Controller
{
    public function __construct(private readonly NotifyAboutMeeting $notifier) {}

    /**
     * Every brand's meetings on one screen.
     *
     * The card on a brand page answers "when do we next see THIS brand"; this
     * answers "what does the week look like", which is the question nobody
     * could ask before without opening every brand in turn.
     *
     * Scoped with visibleTo(), like every other list: an Equipo member sees
     * the meetings of the brands they are on and learns nothing about the rest.
     */
    public function index(Request $request): View
    {
        $brands = Client::query()->visibleTo($request->user())->orderBy('name')->get();

        $meetings = Meeting::query()
            ->whereIn('client_id', $brands->modelKeys())
            // reminders: the row prints which notices went out, so without this
            // the roster is one query per meeting.
            ->with(['client', 'reminders'])
            ->orderByDesc('scheduled_at')
            ->get();

        // The month being looked at. ?mes=2026-09 rather than an offset, so a
        // link to a month stays that month when it is opened tomorrow.
        $month = $this->monthFrom($request->string('mes')->value());

        return view('admin.meetings.index', [
            'brands' => $brands,
            'upcoming' => $meetings->filter(fn (Meeting $m) => $m->isUpcoming())
                ->sortBy('scheduled_at')
                ->values(),
            'past' => $meetings->reject(fn (Meeting $m) => $m->isUpcoming())->values(),
            'month' => $month,
            'weeks' => $this->weeksOf($month, $meetings),
        ]);
    }

    /** The new-meeting screen. Its one extra field is which brand. */
    public function create(Request $request): View
    {
        return view('admin.meetings.create', [
            'brands' => Client::query()->visibleTo($request->user())->orderBy('name')->get(),
            // Prefilled when the button was pressed from a brand's own page.
            'selected' => $request->string('marca')->value(),
        ]);
    }

    public function store(StoreMeetingRequest $request, Client $client): RedirectResponse
    {
        $meeting = $client->meetings()->create([
            ...$request->meetingAttributes(),
            'created_by' => $request->user()->id,
        ]);

        return $this->done($meeting, MeetingScheduled::CREATED, $request->channels(),
            "Reunión «{$meeting->title}» agendada.");
    }

    public function update(StoreMeetingRequest $request, Client $client, Meeting $meeting): RedirectResponse
    {
        $wasAt = $meeting->scheduled_at;

        $meeting->update($request->meetingAttributes());

        // Only a date change is worth calling a reschedule. Fixing a typo in
        // the agenda should not tell everybody the meeting moved.
        $moved = ! $meeting->scheduled_at->equalTo($wasAt);

        $event = $moved ? MeetingScheduled::MOVED : MeetingScheduled::CREATED;

        // New date, new reminder windows. Without this the rows logged against
        // the old date would suppress every reminder for the new one — see
        // Meeting::rearmReminders().
        if ($moved) {
            $meeting->rearmReminders();
        }

        return $this->done($meeting, $event, $request->channels(),
            "Reunión «{$meeting->title}» actualizada.");
    }

    /**
     * Cancel rather than delete.
     *
     * The client was told this meeting exists and may still be looking for it;
     * a row that vanishes reads as a bug. The notification is what closes the
     * loop, so it is offered here too.
     */
    public function cancel(Client $client, Meeting $meeting): RedirectResponse
    {
        // Assigned rather than mass-updated: cancelled_at is deliberately not
        // fillable, because no request should ever be able to post it.
        $meeting->cancelled_at = now();
        $meeting->save();

        return $this->done($meeting, MeetingScheduled::CANCELLED,
            ['database', 'mail'], "Reunión «{$meeting->title}» cancelada.");
    }

    public function destroy(Client $client, Meeting $meeting): RedirectResponse
    {
        $title = $meeting->title;

        $meeting->delete();

        return back()->with('status', "Reunión «{$title}» eliminada. No se avisó a nadie.");
    }

    /**
     * The month to draw, from ?mes=YYYY-MM. Anything unparseable is this month
     * rather than an error: a mistyped URL should show a calendar.
     */
    private function monthFrom(string $value): Carbon
    {
        try {
            return $value === ''
                ? now()->startOfMonth()
                : Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    /**
     * The month as rows of seven days, each with the meetings that fall on it.
     *
     * Built here rather than in the view: a calendar is a loop with three edge
     * cases — the days before the 1st, the days after the last, and which day
     * the week starts on — and Blade is a bad place to keep them straight.
     *
     * Weeks start on MONDAY. Carbon's default is Sunday in some locales and a
     * calendar that starts on the wrong day is read wrong at a glance.
     *
     * @param  Collection<int, Meeting>  $meetings
     * @return array<int, array<int, array{date: Carbon, meetings: array<int, Meeting>}>>
     */
    private function weeksOf(Carbon $month, $meetings): array
    {
        $byDay = $meetings->groupBy(fn (Meeting $m) => $m->scheduled_at->toDateString());

        $cursor = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $last = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $week = [];

        while ($cursor <= $last) {
            $week[] = [
                'date' => $cursor->copy(),
                'meetings' => $byDay->get($cursor->toDateString(), collect())->all(),
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor->addDay();
        }

        return $weeks;
    }

    /**
     * Notify, then land back where the click came from, saying what actually
     * left the building — not what was meant to.
     *
     * @param  array<int, string>  $channels
     */
    private function done(
        Meeting $meeting,
        string $event,
        array $channels,
        string $message,
    ): RedirectResponse {
        $result = $this->notifier->handle($meeting, $event, $channels);

        $said = $message.' '.$this->notifier->summarise($result, $channels);

        // The one exception to back(): the new-meeting screen would otherwise
        // answer with itself, and the meeting somebody just made would not be
        // anywhere on the page. A whitelisted flag rather than a URL in the
        // form — a redirect target a request can name is a redirect anywhere.
        return request()->input('desde') === 'agenda'
            ? redirect()->route('admin.meetings.index')->with('status', $said)
            : back()->with('status', $said);
    }
}
