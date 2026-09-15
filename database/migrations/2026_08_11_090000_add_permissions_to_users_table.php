<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Per-section access for client members, as
             * {"estrategia":"read","entregas":"write"} — App\Enums\PortalSection
             * keys, App\Enums\AccessLevel values. A missing key means no access.
             *
             * A JSON column rather than a pivot table: permissions are only ever
             * read for the signed-in user and written as a whole set from one
             * form, so there is nothing to query across users and nothing to
             * join. Move to a table the day either of those stops being true.
             *
             * Null, not [], for owners and Breakfast staff — they are not
             * "granted nothing", the map does not apply to them at all.
             */
            $table->json('permissions')->nullable()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
