<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssetSource;
use App\Enums\AssetVisibility;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Keep the files somebody attached to a turn with Brandy — brief point 3.
 *
 * Until now the bytes went to the provider and were dropped:
 * brand_onboarding_messages.attachments stores filenames, never bytes. So a
 * brandbook read by the assistant had to be uploaded a SECOND time to end up
 * in the brand's folder — which is the whole complaint this answers.
 *
 * ⚠️ IT NEVER THROWS. Keeping a copy is a convenience attached to a turn that
 * has already cost a paid API call; a full disk or a permissions problem must
 * not turn a good answer into a 500. Failures are reported and the turn
 * continues, exactly as InviteUserToClient does with a failed mail.
 *
 * Used by BOTH assistants that take files, so a file kept from the dashboard
 * and one kept from the process board are stored, named and gated identically.
 */
class KeepAssistantAttachments
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, BrandAsset>
     */
    public function handle(?Client $client, array $files, ?int $userId = null): array
    {
        $kept = [];

        foreach ($files as $file) {
            $asset = $this->keep($client, $file, $userId);

            if ($asset !== null) {
                $kept[] = $asset;
            }
        }

        return $kept;
    }

    private function keep(?Client $client, UploadedFile $file, ?int $userId): ?BrandAsset
    {
        try {
            $folder = BrandAsset::folderFor($client, AssetSource::Referencia);
            $original = $file->getClientOriginalName();

            // The stored name is random and the real one lives on the row, like
            // every other upload here: two people attaching "toolkit.pdf" in the
            // same minute must not overwrite each other, and the filename a
            // designer chose is not a safe path component.
            $path = $file->storeAs(
                $folder,
                Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
                'local',
            );

            if ($path === false) {
                return null;
            }

            return BrandAsset::create([
                'client_id' => $client?->id,
                'uploaded_by' => $userId,
                'title' => pathinfo($original, PATHINFO_FILENAME) ?: $original,
                // ⚠️ INTERNO, always. Whatever arrives in a conversation is
                // working material until a person says otherwise — briefs,
                // drafts, screenshots of things that are not decided. A wrong
                // "compartido" shows a client something nobody meant them to
                // see, and that cannot be taken back; a wrong "interno" shows
                // them nothing and is one click to fix.
                'visibility' => AssetVisibility::Interno,
                'source' => AssetSource::Referencia,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $original,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            // The turn is worth more than the copy. See the class docblock.
            report($e);

            return null;
        }
    }
}
