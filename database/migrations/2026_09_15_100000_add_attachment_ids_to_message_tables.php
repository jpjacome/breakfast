<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a turn point at the files it carried — item 4 of the cycle.
 *
 * Both message tables already store `attachments`: the file NAMES, never the
 * bytes, because re-inlining a screenshot into every later question would
 * multiply a conversation's cost by the size of its first image (CLAUDE.md §7).
 * That is still right, and those columns do not change.
 *
 * But a name cannot be shown. Until now a person attached a screenshot, Brandy
 * answered about it, and the picture vanished from the conversation — the
 * transcript renders the body and nothing else, so on reload you get an answer
 * discussing an image nobody can see.
 *
 * Since brief point 3 the file IS kept, as a BrandAsset behind the gated
 * /archivos/{asset} route. This column is the join: which asset each attachment
 * became.
 *
 * ⚠️ A SECOND COLUMN RATHER THAN A NEW SHAPE FOR THE OLD ONE. `attachments` is
 * read by AssistantMessage::toLlmMessage(), which puts the names into the
 * prompt — turning it into a list of objects would change what the model is
 * told, to solve a problem the model does not have. Names are what the model
 * needs; ids are what the screen needs. They are different questions.
 *
 * ⚠️ NULLABLE, AND OFTEN NULL ON PURPOSE. Every turn from before point 3 has
 * names and no files, because the bytes were dropped. Those render as a chip
 * with the filename, which is exactly as much as is known about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['assistant_messages', 'brand_onboarding_messages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('attachment_ids')->nullable()->after('attachments');
            });
        }
    }

    public function down(): void
    {
        foreach (['assistant_messages', 'brand_onboarding_messages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('attachment_ids');
            });
        }
    }
};
