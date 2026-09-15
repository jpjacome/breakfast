<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request AI cost and performance ledger.
 *
 * NOTE ON client_id / user_id: intentionally plain indexed columns with no
 * foreign key. The clients table is being defined in parallel; adding a
 * constraint here would couple this migration to that one's final shape and
 * ordering. Add the constraints in a follow-up once both are settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // What was asked for.
            $table->string('provider', 32)->default('deepseek');
            $table->string('model', 64);
            $table->string('operation', 32)->default('question');

            // Token accounting. cache_hit_tokens is the health metric: if it
            // stays at 0 across repeat questions for one client, the prompt
            // prefix is unstable and costs are ~150x higher than they should be.
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cache_hit_tokens')->default(0);
            $table->unsignedInteger('cache_miss_tokens')->default(0);

            // Integer micro-USD (1e-6 USD). Avoids float drift when summing;
            // a single cached question costs well under one cent.
            $table->unsignedBigInteger('cost_micro_usd')->default(0);

            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('succeeded')->default(true);
            $table->string('failure_reason')->nullable();

            // Which version of the brand context produced this. Lets you audit
            // an answer after the context has been edited.
            $table->string('context_fingerprint', 32)->nullable();

            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
