<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation: where one thread starts and stops — item 5.
 *
 * ⚠️ WITHOUT THIS THERE IS NO SUCH THING AS "A CONVERSATION" IN THIS APP.
 * `assistant_messages` is an endless run of turns per user per surface, and
 * what reaches the model is "the last 20 of them". So a brand-new subject
 * inherits whatever was being discussed before lunch, there is no history to
 * open, and nothing can be summarised because nothing has a beginning.
 *
 * §3 of the brief asks for exactly the three things this makes possible: a new
 * session opens a new chat, the previous ones stay in accessible history, and
 * the thread is NOT limited to the last N exchanges.
 *
 * ⚠️ IT CARRIES THE SUMMARY, AND THE SUMMARY IS WHAT REPLACES THE CAP. Past a
 * budget of characters the early turns are folded into `summary` and the model
 * reads that plus the turns since `summarised_through_id`. Not a cap on what it
 * may know — a change in how the old part is carried.
 *
 * ⚠️ `client_id` IS NULLABLE AND MEANS DIFFERENT THINGS PER SURFACE, which is
 * the one trap here. On the portal it is the brand the conversation is about
 * and scopes the history list; on the dashboard it is whatever brand happened
 * to be selected, and the assistant deliberately crosses brands, so the list
 * there must NOT be scoped by it. See AssistantMessage::thread().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'admin' | 'portal' | 'egg' — AssistantMessage::SURFACE_*.
            $table->string('surface', 16);

            // See the class note: not an ownership column, and not a filter on
            // every surface.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // What it is about, for the history panel. Written from the first
            // question rather than asked for — nobody titles a chat they have
            // not had yet.
            $table->string('title')->nullable();

            /*
             * The folder somebody dragged it into, or none.
             *
             * ⚠️ nullOnDelete, NEVER cascade. Deleting a folder must not delete
             * the conversations in it — that is somebody losing a month of work
             * by tidying up, and it is the single most expensive mistake this
             * panel could let them make. The conversations fall back to the
             * unfiled list, where they are still there to be found.
             */
            $table->foreignId('folder_id')->nullable()
                ->constrained('conversation_folders')->nullOnDelete();

            // Somewhere to put a finished conversation that is not rubbish.
            // Hidden from the list, kept in full, one click back.
            $table->timestamp('archived_at')->nullable();

            /*
             * ⚠️ SOFT, and for a reason beyond habit: a conversation is a
             * record of what somebody was told, and "delete" in a side panel is
             * one careless click next to "archive". The row survives; only the
             * person's own list stops showing it.
             */
            $table->softDeletes();

            /*
             * What the folded-up part of this conversation said.
             *
             * ⚠️ THE ONLY PLACE IN THIS APP WHERE MODEL OUTPUT BECOMES FACT ON
             * A LATER TURN. Everywhere else — proposals, Egg layers,
             * entregables — a person accepts every word before it counts. This
             * just starts being her memory, which is why it is readable from
             * the meter and editable there.
             */
            $table->text('summary')->nullable();

            // The last turn the summary covers. Everything after it is replayed
            // verbatim, so "hazla más corta" still has something to point at.
            $table->unsignedBigInteger('summarised_through_id')->nullable();

            $table->timestamps();

            // Every read is "this person's conversations on this surface,
            // newest first".
            $table->index(['user_id', 'surface', 'updated_at']);

            // And the panel's other read: what is in this folder.
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
