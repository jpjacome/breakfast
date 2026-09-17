<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an image in a brand's folder LOOKS LIKE, in words.
 *
 * ⚠️ THIS EXISTS SO THE BRAND EGG NEVER HAS TO READ AN IMAGE. The Egg is
 * composed from database fields of the brand and nothing else — that is the
 * rule that keeps it honest (docs/brand-egg.md §1). A logo, an emblem or a
 * colour sheet is a file, not a field, so before layer 4 can synthesise
 * anything visual the picture has to become text ONCE and live here.
 *
 * ⚠️ AND IT IS A FIELD OF THE BRAND, NOT A CACHE. That distinction is the whole
 * design. An earlier plan fed layer 4 from clients.document_digest — the
 * toolkit — which is the model's unreviewed reading of an uploaded PDF and sits
 * at the BOTTOM of the assistant's tiers precisely because nobody checked it.
 * Promoting that into the Egg would have put unreviewed material at the top of
 * the brand's memory. A reading stored HERE is different: it hangs off a row a
 * person filed on purpose, it is visible beside the file, and it can be
 * corrected. That makes it brand data.
 *
 * `read_at` is not decoration: it is what makes a stale reading detectable. A
 * file replaced under the same row leaves a description of the old picture, and
 * comparing the two timestamps is the only way to notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            // TEXT, not VARCHAR: a description of a brandbook spread runs to a
            // paragraph or two, and the same reasoning as brand_deliverables
            // applies — a long VARCHAR counts in full against MySQL's row
            // limit while a TEXT leaves a pointer.
            $table->text('visual_reading')->nullable()->after('size_bytes');
            $table->timestamp('read_at')->nullable()->after('visual_reading');
        });
    }

    public function down(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->dropColumn(['visual_reading', 'read_at']);
        });
    }
};
