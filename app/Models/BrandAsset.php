<?php

namespace App\Models;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\AssetVisibility;
use App\Models\Concerns\DescribesAFile;
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
    /*
     * How this file reads — its kind, its icon, its size in words.
     * Shared with UserFile, because a screenshot must not be an image in
     * one transcript and a paperclip in another.
     */
    use DescribesAFile;
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
        'type',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'visibility' => AssetVisibility::class,
            'source' => AssetSource::class,
            'type' => AssetType::class,
            // ⚠️ CAST, OR THE STALENESS CHECK SILENTLY NEVER FIRES.
            // DescribeBrandAsset compares read_at against updated_at to notice
            // a file replaced under the same row; uncast, read_at is a string
            // and greaterThan() would be asked to compare a Carbon with it.
            'read_at' => 'datetime',
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

    /** Shown inline in an entregable rather than linked. */
}
