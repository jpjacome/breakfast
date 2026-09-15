<?php

namespace App\Models;

use App\Enums\AssetSource;
use App\Enums\AssetVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One file in a brand's folder.
 *
 * ⚠️ Stored under storage/app/marcas/{slug}/assets/, NOT under public/. A file
 * in public/ is readable by anyone who guesses the URL, and one brand's
 * brandbook reaching another is the worst bug this app could have. Everything
 * is served by BrandAssetController, which checks who is asking.
 *
 * That also means storage:link is not needed — it is unreliable on this host,
 * and nothing here depends on it.
 */
class BrandAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'uploaded_by',
        'title',
        'visibility',
        'source',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'visibility' => AssetVisibility::class,
            'source' => AssetSource::class,
        ];
    }

    protected static function booted(): void
    {
        // Keep the stored object and the row in step. Without this, deleting an
        // asset leaves an orphaned file on disk forever.
        static::deleted(function (BrandAsset $asset) {
            Storage::disk($asset->disk)->delete($asset->path);
        });
    }

    /**
     * Only the files the brand is allowed to see.
     *
     * ⚠️ THE PORTAL MUST USE THIS. User::canReachBrandAsset() already refuses a
     * client the FILE, so an internal one cannot be downloaded either way — but
     * a list built without this scope would print the row, and a brand reading
     * the name of a contract draft it cannot open has still been told the
     * contract exists. The gate stops the download; this stops the telling.
     */
    public function scopeSharedWithClient(Builder $query): Builder
    {
        return $query
            ->whereNotNull('client_id')
            ->where('visibility', AssetVisibility::Compartido->value);
    }

    /** Breakfast only. The brand is never shown it and never told it exists. */
    public function isInternal(): bool
    {
        return $this->visibility->isInternal();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The folder a brand's files live in.
     *
     * Keyed by slug rather than id so the folder is legible over FTP, which is
     * how this host is actually administered. Slugs are unique across trashed
     * rows too — see Client::uniqueSlug().
     */
    public static function folderFor(?Client $client, AssetSource $source = AssetSource::Subida): string
    {
        return self::brandFolder($client).'/'.$source->folder();
    }

    /**
     * The directory that belongs to one brand, or the one that belongs to none.
     *
     * ⚠️ THE UNFILED FOLDER IS PREFIXED WITH AN UNDERSCORE so it can never
     * collide with a brand. Slugs are lowercase letters, digits and hyphens
     * (Client::uniqueSlug), so no brand can ever be called _sin-marca — which
     * matters because a collision would put one brand's files inside another
     * folder over FTP, where nothing would warn anybody.
     *
     * It sits BESIDE the brands rather than inside one, because a file nobody
     * has attributed yet belongs to no brand, and parking it in an arbitrary
     * one is how it gets found by the wrong person later.
     */
    public static function brandFolder(?Client $client): string
    {
        return $client === null ? 'marcas/_sin-marca' : "marcas/{$client->slug}";
    }

    /**
     * The URL that goes into an entregable's text.
     *
     * A route, never a file path: it survives the file being replaced, and it
     * is the thing that gets permission-checked on the way through.
     */
    public function url(): string
    {
        return route('assets.download', $this);
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

    public function extension(): string
    {
        return mb_strtoupper(pathinfo($this->original_name, PATHINFO_EXTENSION)) ?: 'FILE';
    }

    /**
     * The asset a URL points at, or null if it is not one of ours.
     *
     * THE ONE PLACE THAT ANSWERS IT. An entregable holds links as plain text —
     * some to this app's own files, most to the wider internet — and the brand
     * page has to tell them apart to know whether to show a picture or print an
     * anchor. See resources/views/components/linked-text.blade.php.
     *
     * ⚠️ IT DECIDES WHAT A LINK *IS*, NEVER WHO MAY SEE IT. Returning a row here
     * grants nothing: the URL it came from still goes through
     * BrandAssetController, which re-checks User::canReachBrandAsset() on the
     * actual request. An <img> whose src the viewer may not fetch renders as a
     * broken image, which is the same 404 the link always gave them.
     *
     * Matched on the route rather than on a string: assets.download is where
     * the path shape is defined, so this cannot drift from it. Host-checked, so
     * a link to some other site that happens to end in /archivos/3 is not read
     * as ours.
     */
    public static function fromUrl(string $url): ?self
    {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($path) || $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return null;
        }

        // "/archivos/12" → 12, and nothing else matches.
        if (preg_match('~^/archivos/(\d+)/?$~', $path, $found) !== 1) {
            return null;
        }

        return static::query()->find((int) $found[1]);
    }

    /** Images get a thumbnail in the grid; everything else gets its icon. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * Video a browser can actually play.
     *
     * ⚠️ NOT EVERY video/* MIME. A .mov or an .avi uploads with a video mime and
     * plays in nothing — putting it in a <video> tag would give the client a
     * black rectangle where a reference should be, which is worse than the link
     * they had before. The three below are what browsers agree on.
     *
     * Extension first, mime second: design machines produce files with an
     * octet-stream mime all the time — the same reason icon() reads the
     * filename rather than the mime.
     */
    public function isPlayableVideo(): bool
    {
        $extension = mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'webm', 'ogv'], true)
            || in_array((string) $this->mime, ['video/mp4', 'video/webm', 'video/ogg'], true);
    }

    /** Shown inline in an entregable rather than linked. */
    public function isViewable(): bool
    {
        return $this->isImage() || $this->isPlayableVideo();
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
