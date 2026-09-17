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
    private function backfill(): void
    {
        if (! Schema::hasTable('assistant_messages')) {
            return;
        }

        $threads = DB::table('assistant_messages')
            ->select('user_id', 'surface')
            ->whereNotNull('user_id')
            ->distinct()
            ->get();

        foreach ($threads as $thread) {
            $id = DB::table('conversations')->insertGetId([
                'user_id' => $thread->user_id,
                'surface' => $thread->surface,
                'title' => 'Conversaciones anteriores',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('assistant_messages')
                ->where('user_id', $thread->user_id)
                ->where('surface', $thread->surface)
                ->update(['conversation_id' => $id]);
        }
    }
};
