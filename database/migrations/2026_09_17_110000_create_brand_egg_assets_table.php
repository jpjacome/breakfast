<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layer 4 of the Brand Egg is an INVENTORY, not a paragraph.
 *
 * ⚠️ THE ONE LAYER THAT IS NOT PROSE, and it has to be. The other four say what
 * a brand IS — its essence, its personality, what it offers, the world it opens
 * — and a paragraph is the right shape for each. "Brand Assets / Icons" is not
 * a claim about the brand; it is a LIST OF THINGS THAT EXIST. Written as prose
 * it can describe a logo but can never point at one, so "muéstrame el logo" had
 * no answer and the layer was a description of files rather than the files.
 *
 * So the layer holds ROW IDS. The description and the URL are fetched from
 * `brand_assets` when something asks, which means:
 *
 *   · correcting a file's description corrects the Egg, with nothing to re-run;
 *   · replacing a logo replaces what the Egg points at;
 *   · deleting a file removes it from the Egg instead of leaving prose that
 *     describes something no longer there.
 *
 * ⚠️ A PIVOT RATHER THAN A JSON ARRAY OF IDS, for the same reason
 * brand_deliverables is 48 columns and not a blob: a foreign key is enforced by
 * the database, and an id in a JSON array is a number nobody checks. The
 * cascade is what stops the Egg pointing at a deleted file.
 *
 * ⚠️ AND IT IS A CURATED SUBSET, not "the brand's files". A brand's folder holds
 * the contract and the pricing sheet too. What goes in the Egg is what
 * Breakfast decided belongs to the identity — which is exactly the kind of
 * judgement the Egg exists to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_egg_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_egg_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_asset_id')->constrained('brand_assets')->cascadeOnDelete();

            // The inventory has an order somebody chose — the primary mark
            // first, not whichever file happened to be uploaded first.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // One row per asset per egg. Adding the same file twice is a
            // double-click, not an instruction.
            $table->unique(['brand_egg_id', 'brand_asset_id']);
            $table->index(['brand_egg_id', 'position']);
        });

        /*
         * The TEXT column goes. Layer 4 was composed as a paragraph for two
         * days; it is a list now, and leaving an unused column would give the
         * Egg two places claiming to hold the same layer. `brand_eggs` has
         * never been deployed, so nothing is lost.
         *
         * ⚠️ CONDITIONAL, AND THE REASON IS A TRAP WORTH KNOWING.
         * `create_brand_eggs_table` does not list its columns — it builds them
         * from `BrandEggLayer::columns()`. So when that method stopped
         * returning the inventory layer, THE PAST CHANGED: a database created
         * from scratch today never gets an `assets` column, while one created
         * yesterday has it. A migration that reads an enum is not immutable,
         * and an unconditional drop here fails every fresh install — which is
         * every test run.
         */
        if (Schema::hasColumn('brand_eggs', 'assets')) {
            Schema::table('brand_eggs', function (Blueprint $table) {
                $table->dropColumn('assets');
            });
        }
    }

    public function down(): void
    {
        Schema::table('brand_eggs', function (Blueprint $table) {
            $table->text('assets')->nullable()->after('beneficios');
        });

        Schema::dropIfExists('brand_egg_assets');
    }
};
