<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person pasted at an assistant. Theirs, not a brand's.
 *
 * ⚠️ THIS EXISTS BECAUSE `brand_assets` WAS TWO UNRELATED THINGS IN ONE TABLE.
 * Since brief point 3 (2026-09-14) every image anybody pasted at any of the
 * three assistants became a `brand_assets` row on that brand — so a client
 * pasting a screenshot of a broken page filed a row in their own brand's
 * folder, beside the logo and the brandbook. The badge said `referencia`, and
 * nothing filtered on it.
 *
 * Keeping the files is right and stays: before it, a brandbook uploaded to an
 * assistant went to the provider and was thrown away, so filing that same
 * toolkit meant uploading it twice. What was wrong is WHOSE it was.
 *
 * So, as of 2026-09-17:
 *
 *   brand_assets       the brand's files. Breakfast files them.
 *   brand_egg_assets   which of those ARE the brand's identity — Egg layer 4
 *   user_files         this. What a person pasted, in their own folder.
 *
 * ⚠️ NO `client_id` HERE, DELIBERATELY. A column for "the brand the
 * conversation was about" is exactly how the last table came to mean two
 * things, and it would answer a question nobody needs from this row: the turn
 * that carries the file already knows its brand, so the link exists through
 * the message without a column that looks like ownership.
 *
 * ⚠️ NO `visibility` EITHER. A brand asset needs one because two different
 * audiences read the same folder; a person's own folder has one audience plus
 * Breakfast, and that is a rule about who may ask, not a property of the file.
 *
 * @see docs/implementaciones.md queued item A
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_files', function (Blueprint $table) {
            $table->id();

            // Whose folder this is. Cascades: the files were only ever theirs,
            // so there is nobody to inherit them.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->timestamps();

            // Every read is "this person's folder, newest first".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_files');
    }
};
