<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10 of docs/multimarca.md — the single-brand columns finally go.
 *
 * `users.client_id` said which ONE brand a person belonged to and
 * `users.permissions` said what they could open in it. Both were replaced on
 * 2026-09-14 by the `brand_user` pivot, which answers the same two questions
 * PER BRAND, and both were left in place then so that migration stayed
 * reversible while the new one was proved in production.
 *
 * It has been. The pivot is live and backfilled, nothing has read these columns
 * since, and as of the commit before this one nothing writes them either — the
 * factory was the last thing that did, and only as shorthand for the tests.
 *
 * ⚠️ down() RESTORES THE COLUMNS BUT NOT THE DATA, and it cannot. A person with
 * three brands has no single client_id to go back to; the pivot holds three
 * rows and one column can hold one answer. So this is a one-way door in
 * practice, and the reason it is safe to walk through is that the pivot has
 * been the only source of truth for a full deploy cycle — not that the rollback
 * works.
 *
 * ⚠️ AND IT IS NOT AN URGENT MIGRATION. These columns are inert: leaving them
 * costs nothing but confusion, so if a deploy has anything else in it, this one
 * can wait for its own quiet pass. Do not run it in the same breath as a
 * feature upload just because both are pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The foreign key goes first: SQLite and MySQL both refuse to drop
            // a column an index still points at, and the constraint is named
            // after the column so dropping it by column name is enough.
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable, and staying empty. See the class docblock: the data
            // cannot come back, because one column cannot hold what the pivot
            // now holds several rows of.
            $table->foreignId('client_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
            $table->json('permissions')->nullable()->after('client_id');
        });
    }
};
