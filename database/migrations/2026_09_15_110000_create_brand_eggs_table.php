<?php

use App\Enums\BrandEggLayer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per brand, one column per Brand Egg layer.
 *
 * The same shape as brand_deliverables and for the same reason: the layer set
 * is CLOSED — five, defined by Breakfast — so what is left is a client_id plus
 * five values, which is one object and not five rows. The columns are generated
 * from BrandEggLayer so the enum stays the single vocabulary; the case value is
 * the column name here, the <g> id in the drawing and the {layer} route
 * segment, all one word (CLAUDE.md §8).
 *
 * TEXT for the same reason as the entregables: MySQL caps a row at 65,535 bytes
 * and a long VARCHAR counts in full against it, while a TEXT leaves a pointer.
 *
 * ⚠️ THE TWO TIMESTAMPS ARE NOT THE STATUS COLUMN CLAUDE.md §8 RULE 2 FORBIDS,
 * and it is worth saying here because it looks like one at a glance. That rule
 * is about a single entregable, where a status beside the text is a second
 * truth that can contradict it. These record two ACTS — somebody composed,
 * somebody approved — asked once for the whole Egg. Nothing per-field can
 * disagree with them.
 *
 * ⚠️ AND THERE IS NO "desactualizado" COLUMN, deliberately. That state is
 * approved_at compared against brand_deliverables.updated_at, derived at read
 * time by Client::brandEggState(). Stored, it would be a flag every screen
 * that edits an entregable has to remember to flip — so it would be wrong the
 * first time somebody added a route. See docs/brand-egg.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_eggs', function (Blueprint $table) {
            $table->id();

            // Unique: a brand has exactly one Egg. Unlike brand_deliverables
            // the row is NOT created with the brand — an Egg is composed, and
            // a brand with no row is the normal case that brandEggOrNew()
            // hands back as a blank object.
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            foreach (BrandEggLayer::columns() as $column) {
                $table->text($column)->nullable();
            }

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_eggs');
    }
};
