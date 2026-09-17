<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Brand Egg conversation, one row per turn.
 *
 * ⚠️ KEYED ON THE BRAND, NOT ON THE PERSON — the opposite of
 * `assistant_messages`, and the difference is deliberate rather than an
 * oversight. That table is keyed on `user_id` precisely so no query in the app
 * can return somebody else's turns (CLAUDE.md §7). Building a brand's Egg is
 * team work: two Breakfast people fill it over a fortnight, and either has to
 * be able to pick up where the other stopped. Same shape as
 * `brand_onboarding_messages`, which is also a thread about one brand.
 *
 * In the database rather than the session for the reason that table gives:
 * filling a brand is not one sitting, and a session that expires overnight
 * would throw away the thread every time.
 *
 * @see docs/brand-egg.md §14
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_egg_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Null on assistant turns. Who typed it — the record of who
            // accepted what, which is the only provenance this app keeps.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'user' | 'assistant'. The wire role, so a turn replays into a
            // request without translation.
            $table->string('role', 16);

            $table->text('body');

            /*
             * Which layer this turn was about — a BrandEggLayer value.
             *
             * ⚠️ NULLABLE, and not only for tidiness. A turn can legitimately
             * belong to no layer: "¿por dónde empezamos?", or an answer that
             * spans two rings. Forcing one would make the assistant pick a
             * layer in order to say something general.
             *
             * ⚠️ AND IT IS WHAT MAKES ➖ "no aplica" DERIVABLE. An optional
             * entregable that is empty AND was already offered in a turn on
             * this thread is one the team has skipped, which is how the
             * checklist reaches "done" without brand_deliverables growing a
             * status column (CLAUDE.md §8 rule 2).
             */
            $table->string('layer', 32)->nullable();

            // Assistant turns only: the proposals made, exactly as they were
            // shown. Kept even when rejected — a rejected proposal is evidence
            // that somebody looked.
            $table->json('proposals')->nullable();

            // What it asked, so a reload shows the question that is waiting.
            $table->json('questions')->nullable();

            $table->timestamps();

            // Every read is "this brand's thread, oldest first".
            $table->index(['client_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_egg_messages');
    }
};
