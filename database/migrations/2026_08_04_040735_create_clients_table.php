<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "client" is a brand Breakfast works with (e.g. The Coffee Club) —
 * not a person. People are users, and a user belongs to a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('industry')->nullable();

            // activo | pausado | cerrado — see App\Enums\ClientStatus
            $table->string('status')->default('activo')->index();

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();

            // Internal notes from the Breakfast side. Never client-visible.
            $table->text('notes')->nullable();

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
