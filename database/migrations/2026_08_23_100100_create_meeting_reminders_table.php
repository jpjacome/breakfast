<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which reminders have already gone out — SEG-01 of the beta review.
 *
 * A meeting gets three: the Monday of its week, 24 hours before, and 1 hour
 * before. This table is how the app knows which of those it has already sent.
 *
 * ⚠️ A ROW MEANS SENT. There is no status column and no "pending" row, for the
 * same reason users.permissions has no "none" level and an empty entregable is
 * pending rather than flagged: absence is a state that cannot go stale, and a
 * second truth about the same fact is one somebody has to remember to move.
 *
 * ⚠️ THE UNIQUE INDEX IS THE MECHANISM, not a tidiness measure. The scheduler
 * on this host is a cPanel cron that may run twice, may run late, and may not
 * be there at all (CLAUDE.md §3). The command is therefore written to be safe
 * to run at any time and any number of times, and this index is what makes that
 * true: the second attempt to log the same window fails at the database rather
 * than mailing a client twice.
 *
 * Cascades with the meeting: a deleted meeting's reminders are about nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            // semana | dia | hora — see App\Enums\ReminderWindow.
            $table->string('window');

            $table->timestamp('sent_at');
            $table->unsignedSmallInteger('recipients')->default(0);

            $table->unique(['meeting_id', 'window']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminders');
    }
};
