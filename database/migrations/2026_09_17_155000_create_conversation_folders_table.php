<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders somebody sorts their own conversations into — item 5.
 *
 * ⚠️ SCOPED EXACTLY LIKE THE CONVERSATIONS THEY HOLD, and that is the whole
 * trap here. A folder is per person, per surface, and — on the portal — per
 * brand, because Brandy's conversations are. Scope them any wider and a folder
 * made while looking at one brand appears empty while looking at another, which
 * reads as lost work. See Conversation::scopeFor().
 *
 * Flat, not a tree. Nested folders need a move UI, a depth limit and a cycle
 * check to earn their keep, and nobody has asked for one — "less is always
 * more" (CLAUDE.md §9). A second level is a migration away if it turns out to
 * be wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_folders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'admin' | 'portal' | 'egg'.
            $table->string('surface', 16);

            // Set on the portal, null on the dashboard — see the class note.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 80);

            // Where it sits in the person's own list, since alphabetical is not
            // what anybody means by "my folders".
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'surface', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_folders');
    }
};
