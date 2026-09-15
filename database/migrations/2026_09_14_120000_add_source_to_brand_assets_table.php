<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep what is attached to Brandy, instead of dropping it — brief point 3.
 *
 * A brandbook uploaded to the assistant went to the provider once and was never
 * stored: brand_onboarding_messages.attachments holds filenames, never bytes.
 * So having that same toolkit in the brand's folder meant uploading it a SECOND
 * time. Two columns close that.
 *
 * ⚠️ ONE TABLE AND ONE LIST, on purpose. The obvious shape is a second table
 * for "reference material", and it is the shape this app already tried and
 * deleted: context_documents divided files by what they FED, put them on
 * screens that hid each other, and people deleted a brandbook thinking it a
 * deliverable (CLAUDE.md §11). Provenance is a column; audience stays
 * AssetVisibility's separate question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            // App\Enums\AssetSource — subida | referencia. Defaulted, so every
            // file that existed before this reads as what it actually was.
            $table->string('source')->default('subida')->after('visibility');
        });

        // ⚠️ SEPARATE STATEMENT. Changing a column and adding one in the same
        // Blueprint is unreliable across drivers, and this runs by hand in a
        // cPanel terminal against MySQL with no rehearsal (CLAUDE.md §3).
        Schema::table('brand_assets', function (Blueprint $table) {
            // A file can now arrive before anyone has said which brand it is
            // about: the dashboard assistant takes a brand from a dropdown, and
            // the dropdown can be empty. Those land in a folder of their own
            // and are Breakfast's alone until somebody files them.
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Unfiled references have no brand to belong to, so they cannot survive
        // the column becoming required again. Removing them is the only honest
        // reversal — and they are, by definition, copies of things that were
        // attached to a conversation rather than originals.
        DB::table('brand_assets')->whereNull('client_id')->delete();

        Schema::table('brand_assets', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
        });

        Schema::table('brand_assets', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
