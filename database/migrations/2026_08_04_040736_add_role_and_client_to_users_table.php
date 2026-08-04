<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // App\Enums\UserRole — admin | equipo | cliente_owner | cliente_miembro
            $table->string('role')->default('cliente_miembro')->index()->after('email');

            // Breakfast-side users (admin, equipo) have no client.
            // Client-side users must have one.
            $table->foreignId('client_id')
                ->nullable()
                ->after('role')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('role');
        });
    }
};
