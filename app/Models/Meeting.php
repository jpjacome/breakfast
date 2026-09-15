<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meeting between Breakfast and a brand.
 *
 * "Who is involved" is not stored: it is everyone in the brand who can read
 * Reuniones. See Client::meetingAudience(), which is the one place that
 * decides it — for the dashboard card and for who gets notified alike.
 */
class Meeting extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'agenda', 'scheduled_at', 'link', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Which of the three reminders have already gone out.
     *
     * A row per window that was sent; no row means not yet. See
     * App\Models\MeetingReminder and SendMeetingReminders.
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(MeetingReminder::class);
    }

    /**
     * Forget the reminders that have not happened yet, so they fire again.
     *
     * ⚠️ CALLED WHEN A MEETING MOVES. The windows are computed from
     * scheduled_at, so a meeting pushed a week out has three new moments — and
     * the rows from the old date would suppress every one of them. Somebody
     * would be told about a meeting on the wrong day and then never reminded
     * about the right one.
     *
     * Only the ones still ahead: a "mañana" notice that already went out for
     * the old date was true when it was sent and cannot be unsent, so clearing
     * it would just send a second one. The change itself is announced by
     * MeetingScheduled::MOVED, which is a different message and already
     * handled.
     */
    public function rearmReminders(): void
    {
        foreach ($this->reminders as $reminder) {
            if ($reminder->window->firesAt($this->scheduled_at)->isFuture()) {
                $reminder->delete();
            }
        }

        $this->unsetRelation('reminders');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Still ahead and still happening.
     *
     * Derived from the date at read time rather than from a status column, so
     * it is right without anything having to run — see the scheduler note in
     * CLAUDE.md.
     */
    public function isUpcoming(): bool
    {
        return ! $this->isCancelled() && $this->scheduled_at->isFuture();
    }

    /** Upcoming meetings, soonest first. The dashboard card takes the first. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at');
    }

    /** Everything already held, most recent first. */
    public function scopePast(Builder $query): Builder
    {
        return $query->where('scheduled_at', '<=', now())
            ->orderByDesc('scheduled_at');
    }

    /** "jueves 20 de agosto, 11:00" — how it reads on the card and in the mail. */
    public function whenInWords(): string
    {
        return $this->scheduled_at->translatedFormat('l j \d\e F, H:i');
    }
}
