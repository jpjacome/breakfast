<?php

use App\Enums\DeliverableItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per brand, one column per entregable.
 *
 * Not 48 rows per brand: an entregable carries nothing but its text — no
 * status, no provenance — so what is left is a client_id plus 48 values, which
 * is one object and not 48. The row IS the brand, readable whole in any
 * database client.
 *
 * The columns are generated from DeliverableItem so the enum stays the single
 * vocabulary. Adding a 49th entregable is a case here plus a migration; that
 * cost is accepted because the taxonomy comes from a closed document
 * (docs/entregables.md), not from something that drifts.
 *
 * TEXT, not VARCHAR: MySQL caps a row at 65,535 bytes and a long VARCHAR
 * counts in full against that limit, while a TEXT leaves only a pointer. With
 * 48 of them, VARCHAR would not fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_deliverables', function (Blueprint $table) {
            $table->id();

            // Unique: a brand has exactly one row, created with the brand.
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            foreach (DeliverableItem::columns() as $column) {
                $table->text($column)->nullable();
            }

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_deliverables');
    }
};
