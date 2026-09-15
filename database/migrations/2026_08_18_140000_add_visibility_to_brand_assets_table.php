<?php

use App\Enums\AssetVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who each file in a brand's folder is for.
 *
 * ⚠️ EVERY EXISTING ROW BECOMES "compartido", which is not a default chosen for
 * convenience: those files are already visible in the client's portal, and some
 * of them are linked from entregables. Defaulting them to internal would
 * silently take away files a brand has been using and break those links in the
 * same breath — a migration that hides data is far worse than one that hides
 * nothing.
 *
 * A string rather than a boolean: `visibility = 'interno'` says what it means
 * when it turns up in a query or over FTP, and `is_private = 1` needs somebody
 * to remember which way round it runs. Same reasoning as ClientStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->string('visibility', 20)
                ->default(AssetVisibility::Compartido->value)
                ->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
