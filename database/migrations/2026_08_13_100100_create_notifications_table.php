<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table — the portal's inbox.
 *
 * It exists because mail alone is not enough here: nothing drains the queue on
 * this host, so a mail failure is silent, and a client who never got the mail
 * would have no other way to learn a meeting was scheduled. The row is written
 * in the same transaction as the meeting, so the inbox is right even when the
 * mail is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
