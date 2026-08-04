<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files Breakfast uploads about a brand: the brief, the strategy deck, the
 * brandbook, workshop notes. These are the raw material the AI layer will
 * later read to build a client's brand context.
 *
 * Distinct from the future `deliverables` table, which is what the CLIENT
 * downloads. A context document is for the system; a deliverable is for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // brief | estrategia | brandbook | investigacion | taller | otro
            $table->string('kind')->default('otro')->index();

            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Set once the file has been parsed into text for the AI layer.
            // Null means "uploaded but not yet indexed".
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_documents');
    }
};
