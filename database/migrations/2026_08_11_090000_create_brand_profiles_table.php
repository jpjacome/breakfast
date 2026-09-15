<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The brand-context form, one row per client.
 *
 * Answers are a JSON map of BrandField value => text rather than one column per
 * question. Forty-odd columns would mean a migration every time the template
 * gains a field, and the template is going to move for a while yet — the schema
 * that matters lives in App\Enums\BrandField, which is also what validates
 * these keys on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();

            // One profile per brand. Deleting the brand takes it with it.
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            // BrandField value => answer. Unknown keys are dropped on save.
            $table->json('answers');

            // Who touched it last, for the "quién lo llenó" line in the form.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
