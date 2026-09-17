<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserFile;
use Illuminate\Http\UploadedFile;

/**
 * Keep the files somebody attached to a turn with Brandy — brief point 3.
 *
 * Until brief point 3 the bytes went to the provider and were dropped: the
 * message tables store filenames, never bytes. So a brandbook read by the
 * assistant had to be uploaded a SECOND time to end up anywhere — which is the
 * complaint this answers.
 *
 * ⚠️ IT WRITES TO THE PERSON'S FOLDER, NOT THE BRAND'S — changed 2026-09-17.
 * It used to create a `brand_assets` row on whatever brand the conversation was
 * about, so a client pasting a screenshot of a broken page filed a row in their
 * own brand's folder beside the logo and the brandbook. The badge said
 * `referencia` and nothing filtered on it.
 *
 * What a brand's assets ARE is now answered in exactly one place — the Egg's
 * inventory, layer 4, picked by a person out of `brand_assets`. A pasted image
 * has never been through that decision, so it goes where it belongs: to whoever
 * pasted it. Keeping the file is still right; whose it was, was wrong.
 *
 * ⚠️ IT NEVER THROWS. Keeping a copy is a convenience attached to a turn that
 * has already cost a paid API call; a full disk or a permissions problem must
 * not turn a good answer into a 500. Failures are reported and the turn
 * continues, exactly as InviteUserToClient does with a failed mail.
 *
 * Used by all three assistants that take files, so a file kept from the
 * dashboard, from the process board and from the client's Brandy is stored,
 * named and gated identically.
 */
class KeepAssistantAttachments
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, UserFile>
     */
    public function handle(?User $owner, array $files): array
    {
        if ($owner === null) {
            // Nobody to give them to. A turn with no authenticated user cannot
            // happen behind these routes, but failing closed costs nothing.
            return [];
        }

        $kept = [];

        foreach ($files as $file) {
            $stored = $this->keep($owner, $file);

            if ($stored !== null) {
                $kept[] = $stored;
            }
        }

        return $kept;
    }

    private function keep(User $owner, UploadedFile $file): ?UserFile
    {
        try {
            $original = $file->getClientOriginalName();

            // The stored name is random and the real one lives on the row, like
            // every other upload here: two people attaching "toolkit.pdf" in the
            // same minute must not overwrite each other, and the filename a
            // designer chose is not a safe path component.
            $path = $file->storeAs(
                UserFile::folderFor($owner),
                UserFile::storedName($original),
                'local',
            );

            if ($path === false) {
                return null;
            }

            return UserFile::create([
                'user_id' => $owner->getKey(),
                'title' => pathinfo($original, PATHINFO_FILENAME) ?: $original,
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
