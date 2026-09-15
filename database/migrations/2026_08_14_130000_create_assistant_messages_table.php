<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's running conversation with an assistant.
 *
 * ⚠️ KEYED ON THE USER, NOT ON A BRAND. That is the whole point: the thread is
 * a person's own, so continuing a conversation means replaying what THEY said
 * and nothing anybody else did. An admin cannot pick up a client's thread by
 * asking for it, because the query never looks outside user_id.
 *
 * Deliberately not brand_onboarding_messages, which is the other shape: that
 * table is per CLIENT and shared by whoever on the Breakfast team is filling
 * the board, because there the thread belongs to the brand being written. Here
 * it belongs to the person asking. Two different questions, two tables — the
 * alternative was one table with a nullable key and a rule about which to use,
 * which is the kind of thing that gets got wrong once and leaks.
 *
 * `surface` distinguishes the assistants a single person may talk to, so a
 * client-side assistant added later does not inherit the answers someone got
 * from the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();

            // Cascade: the thread is the person's, so it goes with them.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'admin' today. A client-facing assistant would add its own.
            $table->string('surface', 32);

            $table->string('role', 16);
            $table->text('body');

            // Which brand was selected when the question was asked, for
            // reading the thread back. Never used to fetch it: the thread is
            // keyed on the user alone. Nullable because "todas las marcas" is
            // the default mode and names no brand.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The only access pattern: the tail of one person's thread.
            $table->index(['user_id', 'surface', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
