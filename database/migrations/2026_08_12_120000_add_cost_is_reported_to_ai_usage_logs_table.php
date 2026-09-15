<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether cost_micro_usd came from the provider or from our price table.
 *
 * OpenRouter reports what it actually charged; DeepSeek does not, so its rows
 * are priced from config/ai.php and are an estimate. Summing the two without
 * saying which is which would put a number on screen that looks like an
 * invoice and is not one.
 *
 * Defaults to false: every row written before this migration was estimated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->boolean('cost_is_reported')->default(false)->after('cost_micro_usd');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('cost_is_reported');
        });
    }
};
