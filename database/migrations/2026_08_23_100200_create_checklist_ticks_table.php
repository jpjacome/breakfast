<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which items of a brand's implementation checklist are done — SEG-05.
 *
 * The checklist itself is one of the 48 entregables
 * (DeliverableItem::ChecklistImplementacion), a block of text Breakfast writes.
 * That does not change. What is new is that the CLIENT can tick its lines, and
 * this is where those ticks live.
 *
 * ⚠️ THE FIRST CLIENT WRITE PATH IN THE PORTAL, after Perfil. CLAUDE.md §5 and
 * §11 said the client side had none at all — Breakfast writes a brand, the
 * brand reads it — and this is a deliberate, narrow exception: the client
 * cannot alter a word of the checklist, only say whether they have done each
 * line. Those sections of CLAUDE.md are updated in the same pass.
 *
 * ⚠️ A ROW MEANS TICKED. Same as meeting_reminders and users.permissions: no
 * status column, no "unticked" row, because absence cannot go stale.
 *
 * item_key is a hash of the item's NORMALISED TEXT, not its position. Two
 * consequences, both intended:
 *
 *   · reordering the checklist keeps every tick — the lines are the same lines
 *   · editing a line drops its tick — an edited item is a different item, and
 *     carrying the tick over would claim somebody confirmed a sentence they
 *     never read
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // sha1 of the normalised line — see ChecklistItem::key().
            $table->string('item_key', 40);

            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');

            // One tick per item per brand. Also what makes a double-submitted
            // form idempotent instead of duplicating rows.
            $table->unique(['client_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_ticks');
    }
};
