<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant has read out of this brand's documents, in its own words.
 *
 * TWO PROBLEMS, ONE COLUMN.
 *
 * The first is that a brandbook was being read and then forgotten. Attachments
 * ride on the turn that carried them and `assistant_messages.attachments` keeps
 * only filenames, never bytes — so the model read a forty-page PDF once, and
 * every follow-up question after that was answered from nothing but the file's
 * name. This is where the reading is kept.
 *
 * The second is that reading a PDF and proposing forty-eight entregables in one
 * generation takes longer than this host allows a request to live (~180s, and
 * long requests starve the pool so the public site starts answering 503).
 * Storing the reading is what lets the proposals be asked for afterwards, in
 * several short calls that each fit comfortably inside the budget.
 *
 * TEXT, appended to rather than replaced: a brand's material arrives over
 * weeks — the brandbook, then the tone guide, then a workshop's notes — and
 * each one is a section under its own heading. Not a table, because there is
 * nothing to query here and no second reader; it is one blob handed to a model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('document_digest')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('document_digest');
        });
    }
};
