<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for each answer, in its own column beside the answers.
 *
 * Kept separate from `answers` rather than nesting every value inside an object
 * so that nothing which already reads answers has to change: BrandProfile::
 * answer(), the completeness score, the markdown handed to the assistant and
 * the validator all keep seeing a flat key => string map.
 *
 * Shape, keyed by BrandField value:
 *
 *   {"descriptor": {"source": "asistente", "confidence": 0.82,
 *                   "evidence": "Brandbook 2026, p. 4", "at": "2026-08-12T09:00:00Z"}}
 *
 * A field missing from this map is a field a person wrote — see
 * App\Enums\BrandFieldSource::parse().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->json('field_sources')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->dropColumn('field_sources');
        });
    }
};
