<?php

namespace App\Models;

use App\Models\Concerns\DescribesAFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A file somebody pasted at an assistant. Theirs.
 *
 * ⚠️ NOT A BRAND ASSET, and the split is the whole point of this class. What a
 * brand's assets ARE is answered in one place — the Egg's inventory, layer 4
 * (`brand_egg_assets`), picked by a person out of `brand_assets`. A screenshot
 * dropped into a chat has never been through that decision and should not sit
 * in the folder that implies it has.
 *
 * ⚠️ EVERY USER HAS A FOLDER, staff and client alike. A Breakfast admin pasting
 * a reference image and a brand's owner pasting a screenshot are the same act,
 * and giving one of them a private folder and the other a row in somebody's
 * brand would be the same confusion again with the roles swapped.
 *
 * @see database/migrations/..._create_user_files_table.php
 */
class UserFile extends Model
{
    use DescribesAFile;

    protected $fillable = [
        'user_id',
        'title',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Where this person's files live on disk.
     *
     * ⚠️ KEYED ON THE ID, NEVER ON THE NAME OR THE ADDRESS. A slug would move
     * when somebody is renamed and an address is not a safe path component;
     * either would orphan every file already written. `usuarios/` sits beside
     * `marcas/` under the same private root, so nothing here is web-reachable
     * — the gated route is the only way in (CLAUDE.md §11).
     */
    public static function folderFor(User $user): string
    {
        return 'usuarios/'.$user->getKey();
    }

    /** A name that cannot collide and cannot carry a path. */
    public static function storedName(string $original): string
    {
        $extension = pathinfo($original, PATHINFO_EXTENSION);

        return Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
    }

    public function url(): string
    {
        return route('user-files.download', $this);
    }

    /**
     * Who may open it.
     *
     * ⚠️ THE ONE PLACE THAT DECIDES IT, and it is deliberately not a
     * permission map. Two answers only: the person it belongs to, and Breakfast
     * staff — who already reach every brand and every brand's files, and who
     * need to see what a client pasted at them in order to answer it.
     *
     * ⚠️ A CLIENT NEVER SEES ANOTHER PERSON'S FOLDER, not even a brand owner
     * looking at their own team. Being able to invite somebody is not being
     * able to read their working material, and an owner who could would make
     * pasting anything at Brandy a thing people think twice about.
     *
     * Fails closed on a null viewer, like every other gate here.
     */
    public function isReachableBy(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $viewer->isBreakfast() || $viewer->getKey() === $this->user_id;
    }
}
