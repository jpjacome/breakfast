<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Marca registrada: Sí / No" on a brand's ficha — SEG-04 of the beta review.
 *
 * ⚠️ NULLABLE, AND THAT IS THE POINT. Three states, not two: registered, not
 * registered, and nobody has said yet. A boolean defaulting to false would put
 * "No" on all forty-odd existing brands the moment this runs, which is a claim
 * about their legal status that nobody made — and the client can see this field.
 * Absence is absence. Same reasoning as users.permissions having no "none"
 * level, and as an empty entregable being pending rather than answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('trademark_registered')->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('trademark_registered');
        });
    }
};
