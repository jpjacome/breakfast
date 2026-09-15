<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files Breakfast hands to a brand: logos, brandbooks, illustrations, audio.
 *
 * The opposite direction to context_documents, which is material the client
 * sends IN so the assistant can read it. These go OUT. Both are files with a
 * client_id and neither is the other, so they stay separate tables.
 *
 * Everything uploaded here shows in the client's files screen — whether or not
 * it belongs to an entregable. An entregable that IS an asset holds the link
 * to one of these rows as its text; nothing links back the other way, because
 * a file can be referenced by several entregables or by none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
