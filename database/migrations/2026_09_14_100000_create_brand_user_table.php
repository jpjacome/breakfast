<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One account, many brands — ACC-01 of the beta review.
 *
 * Until now a client user belonged to exactly ONE brand: users.client_id, plus
 * a permissions map and a role that also said which side of the app they were
 * on. A person working with two brands needed two accounts and two addresses,
 * because users.email is unique.
 *
 * This pivot replaces all three for client users. What belongs to the PERSON
 * stays on users (name, address, password, 2FA); what belongs to the person
 * IN A BRAND moves here — their role there and what they may open there.
 *
 * ⚠️ WHY THE PERMISSIONS MAP MOVES. "Puede ver Reuniones" was never a fact
 * about a person; it was a fact about a person in a brand. One map per account
 * only looked adequate while an account had one brand, and the moment it has
 * two, a single map either grants both or neither. Its SHAPE does not change —
 * {section_value: access_level}, no "none" level, Read or absent (see
 * App\Enums\PortalSection and User::grantCeiling).
 *
 * ⚠️ users.client_id AND users.permissions ARE DELIBERATELY LEFT IN PLACE.
 * Production migrations are run by hand in a cPanel terminal with no staging
 * rehearsal (CLAUDE.md §3), so this one has to be reversible without losing
 * anything — and it is only reversible while the columns it copied FROM are
 * still there. They are dead the moment this runs: nothing reads them, and a
 * test pins that. A second migration drops them once the live app has run on
 * the pivot for a while. See docs/multimarca.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // App\Enums\BrandRole — owner | miembro
            $table->string('role')->default('miembro');

            // The same map as users.permissions, one per brand.
            $table->json('permissions')->nullable();

            $table->timestamps();

            // A person is in a brand once or not at all. Two rows would be two
            // answers to "what may they open here", which is the whole thing
            // this table exists to make unambiguous.
            $table->unique(['client_id', 'user_id']);
        });

        $this->backfill();
    }

    /**
     * Every existing client user becomes exactly one row.
     *
     * No reconciliation is needed and that is worth stating, because it is the
     * reason this migration is mechanical rather than risky: users.email is
     * UNIQUE, so one human could never hold two accounts on the same address —
     * there are no duplicates in the data to merge. Merging two accounts that
     * happen to belong to the same person is a separate, manual job.
     *
     * Chunked rather than loaded whole: this runs in a cPanel web terminal
     * against MySQL, where memory is the scarce thing (CLAUDE.md §3).
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('client_id')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($now) {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'client_id' => $user->client_id,
                        'user_id' => $user->id,
                        // The old role said both things at once; only the half
                        // about the brand survives here.
                        'role' => $user->role === 'cliente_owner' ? 'owner' : 'miembro',
                        'permissions' => $user->permissions,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('brand_user')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // The columns this copied from were never dropped, so there is nothing
        // to restore — dropping the table is the whole reversal.
        Schema::dropIfExists('brand_user');
    }
};
