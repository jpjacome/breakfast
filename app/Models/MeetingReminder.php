<?php

namespace App\Models;

use App\Enums\ReminderWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder that has already gone out.
 *
 * A row means sent; no row means not yet. There is no third state, so nothing
 * has to be moved from one to another and nothing can contradict the mail that
 * actually left. See the migration for the full argument.
 *
 * No timestamps: sent_at is the only time this row has, and created_at would be
 * the same instant under a second name.
 */
class MeetingReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['meeting_id', 'window', 'sent_at', 'recipients'];

    protected function casts(): array
    {
        return [
            'window' => ReminderWindow::class,
            'sent_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
