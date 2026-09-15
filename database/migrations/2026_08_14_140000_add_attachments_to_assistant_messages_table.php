<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What came with a turn: a pasted screenshot, a recorded voice note.
 *
 * ⚠️ THE NAMES, NEVER THE BYTES. The transcript is rebuilt on every page load
 * and has to read right — "mandaste captura.png" — but the file itself was
 * inlined into one request and is gone. Keeping a 40MB voice note in a text
 * column so a chip can say "nota.wav" would be absurd, and the model has
 * already read it by the time this row is written.
 *
 * Same shape as brand_onboarding_messages.attachments, which records filenames
 * for exactly the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
