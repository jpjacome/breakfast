<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each of the three steps started and finished, per brand.
 *
 * A row exists only once a step has been started, so "not started" needs no
 * representation — the absence is it. Same reasoning as users.permissions,
 * where there is no "none" level.
 *
 * Dates rather than a current_step column on clients: this way the timeline is
 * derivable at read time and nothing depends on a scheduled job having run.
 * The current step is whichever row has started_at and no completed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_process_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('step');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_process_steps');
    }
};
