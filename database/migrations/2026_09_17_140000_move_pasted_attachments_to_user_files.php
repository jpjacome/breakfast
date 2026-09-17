<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Move what people pasted at an assistant out of the brands' folders.
 *
 * Since 2026-09-14 every image attached to any of the three assistants became a
 * `brand_assets` row on whatever brand the conversation was about. As of
 * 2026-09-17 those belong to the person who pasted them (`user_files`), and
 * `brand_assets` holds only what Breakfast filed for a brand — see that table's
 * migration for why.
 *
 * ⚠️ IT IDENTIFIES THEM FROM `attachment_ids`, NOT FROM `source`. Both would
 * look right and only one is: `source = referencia` ALSO covers files a
 * Breakfast admin deliberately filed as a reference through the file manager,
 * and those are the brand's and must not move. The message tables name the
 * exact rows a chat produced, so the selection is a fact rather than a guess.
 *
 * ⚠️ IT REWRITES THE TURNS IN THE SAME PASS. `attachment_ids` is positional —
 * the Nth name is the Nth file — so a row moved without its pointer being
 * updated would silently blank a picture in a conversation, or worse, point at
 * whatever brand_asset later took that id. Old and new ids are mapped one to
 * one and every turn is rewritten.
 *
 * ⚠️ A ROW WITH NO `uploaded_by` STAYS PUT. It cannot be attributed, and
 * guessing an owner for somebody's private folder is worse than leaving a file
 * where it already is. In practice KeepAssistantAttachments always recorded the
 * user, so this is a guard rather than a case.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [];

        foreach ($this->pastedIds() as $id) {
            $asset = DB::table('brand_assets')->find($id);

            if ($asset === null || $asset->uploaded_by === null) {
                continue;
            }

            $path = $this->moveFile($asset);

            $map[$id] = DB::table('user_files')->insertGetId([
                'user_id' => $asset->uploaded_by,
                'title' => $asset->title,
                'disk' => $asset->disk,
                'path' => $path,
                'original_name' => $asset->original_name,
                'mime' => $asset->mime,
                'size_bytes' => $asset->size_bytes,
                'created_at' => $asset->created_at,
                'updated_at' => $asset->updated_at,
            ]);

            DB::table('brand_assets')->where('id', $id)->delete();
        }

        $this->remapTurns($map);
    }

    /**
     * ⚠️ IRREVERSIBLE, AND SAYING SO IS THE HONEST ANSWER. Going back would
     * mean deciding which brand each file belonged to, and the whole reason it
     * moved is that the answer was "none of them" — the conversation's brand
     * was never the file's owner. The rows and the files survive in
     * `user_files`; nothing is destroyed, it simply does not go back.
     */
    public function down(): void {}

    /**
     * Every brand_asset id that a chat turn points at.
     *
     * @return array<int, int>
     */
    private function pastedIds(): array
    {
        $ids = [];

        foreach (['assistant_messages', 'brand_onboarding_messages'] as $table) {
            foreach (DB::table($table)->whereNotNull('attachment_ids')->pluck('attachment_ids') as $json) {
                foreach ((array) json_decode((string) $json, true) as $id) {
                    if (is_numeric($id)) {
                        $ids[(int) $id] = (int) $id;
                    }
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Copy the bytes into the person's folder, and return the new path.
     *
     * Copy-then-delete rather than move, and the original row is only dropped
     * after the insert succeeds: a half-finished migration should leave a file
     * reachable from somewhere rather than from nowhere. If the source is
     * already gone — a deploy that overwrote storage/ — the row still moves,
     * carrying its name, exactly as a missing brand asset does today.
     */
    private function moveFile(object $asset): string
    {
        $disk = Storage::disk($asset->disk);
        $target = 'usuarios/'.$asset->uploaded_by.'/'.basename($asset->path);

        if ($disk->exists($asset->path) && ! $disk->exists($target)) {
            $disk->copy($asset->path, $target);
            $disk->delete($asset->path);
        }

        return $target;
    }

    /**
     * Point every turn at the file's new id.
     *
     * @param  array<int, int>  $map  old brand_asset id => new user_file id
     */
    private function remapTurns(array $map): void
    {
        if ($map === []) {
            return;
        }

        foreach (['assistant_messages', 'brand_onboarding_messages'] as $table) {
            foreach (DB::table($table)->whereNotNull('attachment_ids')->get(['id', 'attachment_ids']) as $turn) {
                $ids = (array) json_decode((string) $turn->attachment_ids, true);

                // Positional, so an id with no mapping keeps its slot as null
                // rather than being dropped — otherwise every attachment after
                // it shifts onto the wrong name.
                $moved = array_map(
                    fn ($id) => is_numeric($id) ? ($map[(int) $id] ?? null) : null,
                    $ids,
                );

                DB::table($table)->where('id', $turn->id)->update([
                    'attachment_ids' => json_encode($moved),
                ]);
            }
        }
    }
};
