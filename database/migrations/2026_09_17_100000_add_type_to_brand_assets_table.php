<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each file in a brand's folder actually is.
 *
 * `brand_assets` knew where a file lived, who could see it and how it arrived —
 * never what it was. So a logo, a colour sheet and a signed contract were three
 * rows told apart only by whatever somebody typed in `title`, "show me the
 * logo" had no answer a query could give, and the Brand Egg's fourth layer
 * could not point at the primary mark rather than at "some file".
 *
 * ⚠️ NULLABLE, AND NULL MEANS "NOBODY HAS SAID" — not `otro`. Every row that
 * exists when this runs gets null, which is honest: nobody has classified them.
 * Defaulting them to `otro` would make a file nobody has looked at
 * indistinguishable from one a person examined and judged miscellaneous. The
 * first is work outstanding; the second is a finished decision, and the screen
 * needs to be able to show the difference.
 *
 * ⚠️ A THIRD INDEPENDENT LABEL, not a new folder. It sits beside `visibility`
 * (who may see it) and `source` (how it arrived) and must never be allowed to
 * decide either — dividing the folder by what files ARE is the mistake that
 * killed `context_documents` (CLAUDE.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->string('type')->nullable()->after('source');

            // The queries this exists for are always "the identity files of
            // THIS brand" — the Egg composing layer 4, or a screen grouping a
            // folder. Neither ever asks across brands.
            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
