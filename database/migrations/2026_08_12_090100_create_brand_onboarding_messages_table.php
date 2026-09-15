<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding conversation, one row per turn.
 *
 * Kept in the database rather than the session for two reasons. Filling a brand
 * is not one sitting — it is a week of somebody finding the next document — and
 * a session that expires overnight would throw away the thread every time.
 * And when a field turns out to be wrong six months later, this is the only
 * record of which document it was read from and who accepted it.
 *
 * Attachments are NOT stored here. They are inlined into the request as data
 * URIs and then gone; what remains is the filename, in `attachments`, so the
 * transcript still reads correctly. A file worth keeping belongs in
 * context_documents, which is what that table is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_onboarding_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Null on assistant turns. The person who typed it, for the record
            // of who accepted what.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'user' | 'assistant'. Matches the wire role so the transcript can
            // be replayed into a request without translation.
            $table->string('role', 16);

            $table->text('body');

            // Assistant turns only: the proposals made, exactly as they were
            // shown. Kept even when rejected — a rejected proposal is evidence
            // that somebody looked.
            $table->json('proposals')->nullable();

            // Questions the assistant asked, and the filenames it was given.
            $table->json('questions')->nullable();
            $table->json('attachments')->nullable();

            $table->timestamps();

            // Every read is "this brand's thread, oldest first".
            $table->index(['client_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_onboarding_messages');
    }
};
