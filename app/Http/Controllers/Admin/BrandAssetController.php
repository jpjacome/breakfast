<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\DescribeBrandAsset;
use App\Enums\AssetType;
use App\Enums\AssetVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandAssetRequest;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Filling a brand's folder. Only Breakfast uploads; the client only reads.
 *
 * A shared file lands in the client's files screen whether or not it belongs to
 * an entregable — the folder is the brand's folder, not a by-product of the
 * board. An entregable that IS an asset holds the file's link as its text, and
 * the copy-link button on this screen is how that link gets there.
 *
 * ⚠️ AND SOME FILES ARE BREAKFAST'S ALONE: the contract, the pricing sheet, the
 * team's working notes. Those live in the same folder with visibility=interno
 * and never reach the brand — see AssetVisibility, and User::canReachBrandAsset()
 * which is what actually refuses the download.
 */
class BrandAssetController extends Controller
{
    /**
     * How long an upload may spend describing images before it stops.
     *
     * ⚠️ PHP'S WEB max_execution_time ON THIS HOST IS 60s (CLAUDE.md §3), and
     * an upload has already spent time receiving the files before any of this
     * starts. A reading is a paid call of a few seconds, so a folder dropped in
     * ten at a time would run the request out and lose the upload itself —
     * which is the one thing the person actually asked for.
     *
     * So the budget stops STARTING readings, and whatever is left keeps
     * read_at null. `assets:describe` picks those up, and the upload always
     * survives. Same shape as EggComposer::BUDGET_SECONDS, for the same reason.
     */
    private const DESCRIBE_BUDGET_SECONDS = 25;

    public function __construct(private readonly DescribeBrandAsset $describe) {}

    public function store(StoreBrandAssetRequest $request, Client $client): RedirectResponse
    {
        /** @var array<int, UploadedFile> $files */
        $files = $request->file('files');
        $single = count($files) === 1;
        $folder = BrandAsset::folderFor($client);
        $visibility = $request->visibility();
        $startedAt = microtime(true);

        foreach ($files as $file) {
            // store() names the file itself, so two uploads called logo.png do
            // not overwrite each other. The original name is kept on the row
            // for display and for the download's filename.
            $path = $file->store($folder, 'local');

            $asset = $client->brandAssets()->create([
                'uploaded_by' => $request->user()->id,
                'title' => $request->titleFor($file->getClientOriginalName(), $single),
                // One choice for the whole upload: these arrive as a batch —
                // a logo pack, a set of notes — and they are the same kind of
                // thing as each other far more often than not.
                'visibility' => $visibility,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);

            /*
             * Read the picture into words, now, while we know it just arrived.
             *
             * ⚠️ THIS IS WHAT LETS THE BRAND EGG SEE AN IMAGE WITHOUT EVER
             * READING ONE. The Egg composes from fields of the brand and never
             * from a file, so a logo only reaches layer 4 once it has become
             * text — see DescribeBrandAsset.
             *
             * ⚠️ IT NEVER THROWS AND IT IS NEVER REQUIRED. A provider outage
             * leaves the file uploaded with an empty reading, which is a column
             * somebody can fill later; failing the upload over it would throw
             * away the thing that was actually asked for.
             */
            if (microtime(true) - $startedAt < self::DESCRIBE_BUDGET_SECONDS) {
                $this->describe->handle($asset, $request->user()->id);
            }
        }

        $count = count($files);

        // The message says who can see it, because that is the half of this
        // action somebody might have got wrong and cannot see from the row
        // they just created.
        $what = $count === 1 ? 'Archivo subido' : "{$count} archivos subidos";

        $who = $visibility->isInternal()
            ? 'Sólo los ve el equipo de Breakfast.'
            : 'Ya los ve la marca en sus archivos.';

        // back(), not a named route: this form is on the process screen and on
        // the file manager, and an upload that answers by moving you to the
        // other one is a bug you have to undo by hand.
        return back()->with('status', "{$what}. {$who}");
    }

    /**
     * Change who may see a file that is already uploaded.
     *
     * ⚠️ THE POINT IS THE MISTAKE, not the tidiness. Picking the wrong option
     * on the upload form puts a contract in front of a client, and without this
     * the only fix would be deleting the file — which also breaks any entregable
     * linking it. One click, no data lost, and the link keeps working for
     * Breakfast either way.
     */
    public function visibility(Client $client, BrandAsset $asset): RedirectResponse
    {
        $moved = $asset->isInternal()
            ? AssetVisibility::Compartido
            : AssetVisibility::Interno;

        $asset->update(['visibility' => $moved]);

        return back()->with('status', $moved->isInternal()
            ? "«{$asset->title}» ya no lo ve la marca."
            : "«{$asset->title}» ya lo ve la marca en sus archivos.");
    }

    /**
     * Say what a file IS — logo, paleta, documento.
     *
     * ⚠️ THE THIRD INDEPENDENT LABEL. It sits beside visibility (who may see
     * it) and source (how it arrived) and decides neither. Classifying a file
     * never moves it, never changes who may open it and never re-files it:
     * dividing a folder by what things ARE is the mistake that killed
     * context_documents (CLAUDE.md §2).
     *
     * An empty value clears it back to null, which means "nobody has said" —
     * deliberately not `otro`, which is a decision somebody made.
     */
    public function type(Request $request, Client $client, BrandAsset $asset): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(AssetType::class)],
        ]);

        $type = $validated['type'] ?? null;

        $asset->update(['type' => $type === '' ? null : $type]);

        return back()->with('status', $asset->type === null
            ? "«{$asset->title}» ya no tiene tipo."
            : "«{$asset->title}» es {$asset->type->label()}.");
    }

    /**
     * Correct what the machine said an image looks like.
     *
     * ⚠️ THIS IS WHAT MAKES THE READING BRAND DATA. DescribeBrandAsset writes
     * the description by looking at the picture, and the Brand Egg composes
     * layer 4 from it — so it has to be something a person can see and fix,
     * not an opaque machine output nobody can reach. A reading nobody can
     * correct is exactly the unreviewed material the Egg may not be built from.
     *
     * ⚠️ read_at MOVES WITH IT. Otherwise a hand-corrected description looks
     * STALE to DescribeBrandAsset::shouldRead() — updated_at would be newer
     * than read_at — and the next backfill would overwrite the correction with
     * a fresh machine reading.
     */
    public function reading(Request $request, Client $client, BrandAsset $asset): RedirectResponse
    {
        $validated = $request->validate([
            'visual_reading' => ['nullable', 'string', 'max:2000'],
        ]);

        $reading = trim((string) ($validated['visual_reading'] ?? ''));

        $asset->forceFill([
            'visual_reading' => $reading === '' ? null : $reading,
            'read_at' => $reading === '' ? null : now(),
        ])->save();

        return back()->with('status', $reading === ''
            ? "Se borró la descripción de «{$asset->title}»."
            : "Descripción de «{$asset->title}» guardada.");
    }

    /**
     * Delete a file.
     *
     * ⚠️ The row going means the file goes too — see BrandAsset::booted(). Any
     * entregable holding this asset's link keeps the link, which will then
     * 404. That is deliberate: silently editing 48 text columns to remove a
     * URL would be a worse surprise than a dead link somebody can see and fix.
     */
    public function destroy(Client $client, BrandAsset $asset): RedirectResponse
    {
        $title = $asset->title;

        $asset->delete();

        return back()
            ->with('status', "«{$title}» eliminado. Si algún entregable lo enlazaba, ese link ya no funciona.");
    }
}
