<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which brands a Breakfast staff member covers.
 *
 * Deliberately NOT users.client_id. That column means "the brand this client
 * user belongs to" and holds exactly one; staff cover many, and staff rows
 * keep client_id null. The two answer different questions and must not share
 * a column — see App\Models\User.
 *
 * Only the Equipo role is scoped by this table. An Admin reaches every brand
 * by role, so their rows here are stored but never consulted; keeping them
 * means demoting an admin to Equipo lands on a real list rather than nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_staff', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One row per pairing, and the index the scope query reads.
            $table->unique(['user_id', 'client_id']);
            $table->index('client_id');
        });

        $this->keepExistingStaffOnEveryBrand();
    }

    /**
     * Nobody loses access the moment this deploys.
     *
     * Before this table, every Breakfast user reached every brand. Creating it
     * empty would silently black out the whole back office for the Equipo role
     * until somebody went through the team one by one. So the current
     * behaviour is written down as data, and narrowing it is a deliberate act
     * in /admin/equipo afterwards.
     */
    private function keepExistingStaffOnEveryBrand(): void
    {
        $clientIds = DB::table('clients')->whereNull('deleted_at')->pluck('id');
        $staffIds = DB::table('users')->where('role', UserRole::Equipo->value)->pluck('id');

        if ($clientIds->isEmpty() || $staffIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($staffIds as $userId) {
            foreach ($clientIds as $clientId) {
                $rows[] = [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('client_staff')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_staff');
    }
};
