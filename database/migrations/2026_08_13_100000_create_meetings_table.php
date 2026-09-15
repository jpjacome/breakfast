<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meeting between Breakfast and a brand.
 *
 * No participants pivot. A meeting belongs to the BRAND, and everyone in the
 * brand who can read Reuniones sees it — which is already the answer to "who
 * is involved", decided by the permission map rather than by a second list
 * somebody has to keep in step with it.
 *
 * cancelled_at rather than deleting the row: a cancelled meeting is something
 * the client was told about and may still be looking for, and a meeting that
 * silently vanishes reads as a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->timestamp('scheduled_at');
            $table->string('link')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The client dashboard asks for "the next one" on every page load.
            $table->index(['client_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
