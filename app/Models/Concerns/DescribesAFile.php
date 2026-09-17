<?php

namespace App\Models\Concerns;

/**
 * How a stored file reads: its kind, its icon, its size in words.
 *
 * THE ONE PLACE THAT DECIDES IT, for the two models that hold a file —
 * `BrandAsset` (a brand's files) and `UserFile` (what a person pasted at an
 * assistant). Extracted 2026-09-17 when the second one appeared, rather than
 * copied: `TurnAttachments` already warns in its own docblock that this
 * reading is fiddly enough to drift, and a screenshot must not be an image in
 * one transcript and a paperclip in another.
 *
 * ⚠️ IT SAYS WHAT A FILE IS, NEVER WHO MAY OPEN IT. Access is the model's own
 * question — `canReachBrandAsset()` for one, ownership for the other — and
 * nothing here grants anything.
 *
 * The user of this trait must have `original_name`, `mime` and `size_bytes`.
 */
trait DescribesAFile
{
    public function extension(): string
    {
        return mb_strtoupper(pathinfo($this->original_name, PATHINFO_EXTENSION)) ?: 'FILE';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * Video a browser can actually play.
     *
     * ⚠️ NOT EVERY video/* MIME. A .mov or an .avi uploads with a video mime and
     * plays in nothing — putting it in a <video> tag would give the viewer a
     * black rectangle where a reference should be, which is worse than the link
     * they had before. The three below are what browsers agree on.
     *
     * Extension first, mime second: design machines produce files with an
     * octet-stream mime all the time — the same reason icon() reads the
     * filename.
     */
    public function isPlayableVideo(): bool
    {
        $extension = mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'webm', 'ogv'], true)
            || in_array((string) $this->mime, ['video/mp4', 'video/webm', 'video/ogg'], true);
    }

    /** Shown inline rather than linked. */
    public function isViewable(): bool
    {
        return $this->isImage() || $this->isPlayableVideo();
    }

    public function humanSize(): string
    {
        return self::formatSize($this->size_bytes);
    }

    /**
     * Spell a byte count.
     *
     * Static because the file manager weighs a whole folder, which is a SUM
     * over rows and not any one of them. One class decides how a size reads,
     * so a folder and the files in it can never be spelled differently.
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }

    /**
     * Tabler icon for this file, rendered as <x-tabler-{icon}>.
     *
     * ON THE EXTENSION, NOT THE MIME. A brand's files arrive from designers'
     * machines and half of them are formats a browser has no mime for — .ai,
     * .indd, .sketch all upload as application/octet-stream, which would put
     * the same blank page on the three things a brand cares most about. The
     * filename is what actually says what a file is here.
     *
     * The list is short on purpose. It covers what Breakfast actually hands a
     * brand — the design sources, the deliverable documents, the packaged
     * folders — and everything else gets the plain sheet rather than a guess.
     * A wrong icon is worse than a neutral one: it tells somebody the file is
     * something it is not.
     */
    public function icon(): string
    {
        $extension = mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'file-type-pdf',
            'doc', 'docx', 'rtf', 'pages' => 'file-type-doc',
            'xls', 'xlsx', 'numbers' => 'file-type-xls',
            'csv' => 'file-type-csv',
            'ppt', 'pptx', 'key' => 'file-type-ppt',
            'txt', 'md', 'markdown' => 'file-text',
            // The design sources. .ai has its own icon in Tabler, which is the
            // one file on this list a brand recognises on sight.
            'ai' => 'file-ai',
            'svg' => 'file-type-svg',
            'psd', 'indd', 'sketch', 'fig', 'xd', 'afdesign', 'afphoto' => 'palette',
            'zip', 'rar', '7z', 'tar', 'gz' => 'file-zip',
            'otf', 'ttf', 'woff', 'woff2', 'eot' => 'typography',
            'mp4', 'mov', 'avi', 'webm', 'mkv' => 'movie',
            'mp3', 'wav', 'm4a', 'ogg', 'aac', 'flac', 'aiff' => 'file-music',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'heic', 'tif', 'tiff' => 'photo',
            default => 'file',
        };
    }
}
