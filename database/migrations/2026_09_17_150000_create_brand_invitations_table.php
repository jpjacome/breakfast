<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation waiting on the invited person's consent — queued item B.
 *
 * ⚠️ IT EXISTS BECAUSE A BRAND OWNER MUST NOT LEARN WHETHER AN ADDRESS HAS AN
 * ACCOUNT. Attaching an existing user on the spot would say so; so does
 * refusing with *"ya existe una cuenta con ese correo"*, which is what the app
 * did until 2026-09-17 while its own comment claimed to be preventing exactly
 * that. Both answers leak, in opposite directions.
 *
 * So an invitation to a known address writes a row here and notifies **that
 * person**. Nothing reaches `brand_user` until they accept. The owner is told
 * the same sentence either way, and cannot tell the two apart.
 *
 * ⚠️ NO `user_id` COLUMN, ON PURPOSE. The invitation is to an ADDRESS: pinning
 * it to a row would mean resolving the account at invite time and then holding
 * a foreign key that answers the very question this table exists to keep
 * quiet — and it would break if that person's address changed before they
 * accepted.
 *
 * @see docs/implementaciones.md queued item B
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Who asked. Kept so the invited person is told who is inviting
            // them — a bare "join this brand" from nobody is a phishing mail.
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email');

            // What they would get, held until they say yes. Written to the
            // pivot at acceptance and never before.
            $table->string('role', 32);
            $table->json('permissions')->nullable();

            // The link. Random, single-use, and the only way to accept.
            $table->string('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();

            $table->timestamps();

            // "Is this address already invited to this brand, and still
            // waiting?" — asked on every invite to avoid two live tokens.
            $table->index(['client_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_invitations');
    }
};
