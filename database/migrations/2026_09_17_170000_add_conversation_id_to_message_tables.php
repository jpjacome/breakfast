<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put every existing turn into a conversation — item 5.
 *
 * ⚠️ NULLABLE, AND IT STAYS NULLABLE. A turn written by a path that has not
 * been taught about conversations yet must not fail; it lands loose and is
 * still readable. Fail open here, unlike everywhere else in this app, because
 * the cost of the alternative is losing somebody's question.
 *
 * ⚠️ THE BACKFILL PUTS EACH PERSON'S WHOLE PAST INTO ONE CONVERSATION PER
 * SURFACE, which is a lie of convenience and worth saying out loud. Those turns
 * have no boundaries — that is exactly what this feature adds — so any split
 * would be invented. One bucket per surface is the only honest reading: it says
 * "everything before conversations existed", and its title says so too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['assistant_messages', 'brand_onboarding_messages', 'brand_egg_messages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('conversation_id')->nullable()->after('id')
                    ->constrained()->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (['assistant_messages', 'brand_onboarding_messages', 'brand_egg_messages'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'conversation_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('conversation_id');
            });
        }
    }

    /**
     * One conversation per person per surface, holding everything they said
     * before conversations existed.
     */
    /**
     * Every turn that already exists gets a conversation to belong to.
     *
     * ⚠️ GROUPED DIFFERENTLY PER SURFACE, and getting this wrong strands
     * history rather than losing it — which is worse, because nothing fails.
     * The dashboard's thread crosses brands on purpose, so it is ONE
     * conversation per person with a null `client_id`. The portal's is per
     * brand (ACC-01), so it is one per person PER BRAND — and a portal
     * conversation stored with a null client would never be found again by a
     * panel that asks for the active brand's.
     *
     * ⚠️ THE EGG AND THE ONBOARDING THREAD ARE NOT BACKFILLED. Their column is
     * added above so the day they get conversations there is no second
     * migration, but `Conversation` is a person's own and those two are the
     * brand's — see that model. Their rows keep a null conversation_id, which
     * is exactly what "not part of this yet" looks like.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('assistant_messages')) {
            return;
        }

        // The dashboard: one per person, crossing brands.
        $admins = DB::table('assistant_messages')
            ->select('user_id')
            ->where('surface', 'admin')
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($admins as $userId) {
            $id = $this->openThread($userId, 'admin', null);

            DB::table('assistant_messages')
                ->where('user_id', $userId)
                ->where('surface', 'admin')
                ->update(['conversation_id' => $id]);
        }

        // The portal: one per person per brand.
        $threads = DB::table('assistant_messages')
            ->select('user_id', 'client_id')
            ->where('surface', 'portal')
            ->whereNotNull('user_id')
            ->distinct()
            ->get();

        foreach ($threads as $thread) {
            $id = $this->openThread($thread->user_id, 'portal', $thread->client_id);

            DB::table('assistant_messages')
                ->where('user_id', $thread->user_id)
                ->where('surface', 'portal')
                // ⚠️ Matches a null client_id too. A portal turn from before
                // the brand was stored on the row would otherwise be left
                // behind by a plain where().
                ->where(fn ($q) => $thread->client_id === null
                    ? $q->whereNull('client_id')
                    : $q->where('client_id', $thread->client_id))
                ->update(['conversation_id' => $id]);
        }
    }

    /** One row, named for what it is: everything from before the panel. */
    private function openThread(int $userId, string $surface, ?int $clientId): int
    {
        return DB::table('conversations')->insertGetId([
            'user_id' => $userId,
            'surface' => $surface,
            'client_id' => $clientId,
            'title' => 'Conversaciones anteriores',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
