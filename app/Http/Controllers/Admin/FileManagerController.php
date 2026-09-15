<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

/**
 * The file manager: every brand a folder, every folder its files.
 *
 * The process screen already uploads into a brand's folder, but only while you
 * are inside that brand's process. This is the other way round — you came here
 * because you are looking for a file and do not yet know whose it is. Same
 * folders, same rows, same storage; a different question being asked.
 *
 * ⚠️ ONE KIND OF FILE. There were two — brand assets and "context documents",
 * material uploaded for the assistant to read and kept out of the client's
 * sight. That split was removed on 2026-08-14: the assistant's context is the
 * 48 entregables and the brand's own details, so the second kind fed nothing
 * while quietly hiding files the team meant the client to have.
 *
 * ⚠️ There is no controller for writing here. Upload and delete are
 * BrandAssetController's, shared with the process and brand screens, because a
 * folder filled from two doors that disagree is a folder nobody trusts.
 */
class FileManagerController extends Controller
{
    /**
     * Every brand as a folder.
     *
     * visibleTo() so equipo sees only the brands they are on — the same reading
     * of covers() the clients list does. It is not the enforcement: show()
     * carries {client}, so EnsureStaffCoversClient guards the way in.
     */
    public function index(Request $request): View
    {
        $clients = Client::query()
            ->visibleTo($request->user())
            ->withCount('brandAssets')
            ->withSum('brandAssets', 'size_bytes')
            ->when($request->string('q')->trim()->value(), fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->get();

        // The unfiled folder is shown only when it has something in it, and only
        // to people who reach every brand: a file nobody has attributed could be
        // about any brand, so showing it to an equipo member who covers three of
        // them would be showing them a fourth brand's material by accident.
        $unfiled = $request->user()->coversEveryBrand()
            ? BrandAsset::query()->whereNull('client_id')
            : null;

        return view('admin.files.index', [
            'clients' => $clients,
            'q' => $request->string('q')->value(),
            'unfiledCount' => $unfiled?->count() ?? 0,
            'unfiledSize' => $unfiled?->sum('size_bytes') ?? 0,
        ]);
    }

    /** One brand's folder, opened. */
    public function show(Request $request, Client $client): View
    {
        return view('admin.files.show', [
            'client' => $client,
            'assets' => $this->sorted($client->brandAssets()->with('uploader'), $request)->get(),
            'sort' => $this->sort($request),
            'dir' => $this->direction($request),
        ]);
    }

    /**
     * The files nobody has filed yet.
     *
     * Attachments to the dashboard assistant, sent while the brand dropdown was
     * empty. They belong to no brand, so they sit beside the brands rather than
     * inside one — parking them in an arbitrary folder is how they get found by
     * the wrong person later.
     *
     * ⚠️ Its own route, and it must be DECLARED BEFORE `archivos/{client}` or
     * the binding claims the word first.
     */
    public function unfiled(Request $request): View
    {
        return view('admin.files.show', [
            'client' => null,
            'assets' => $this->sorted(
                BrandAsset::query()->whereNull('client_id')->with('uploader'),
                $request,
            )->get(),
            'sort' => $this->sort($request),
            'dir' => $this->direction($request),
        ]);
    }

    /**
     * Order a folder by what somebody is actually looking for.
     *
     * ⚠️ The column is chosen from a fixed map, never taken from the query
     * string — an orderBy built from user input is an injection point, and
     * "sort by whatever you type" is not a feature anybody asked for.
     */
    private function sorted(Builder|HasMany $query, Request $request): Builder|HasMany
    {
        $column = match ($this->sort($request)) {
            'nombre' => 'title',
            'peso' => 'size_bytes',
            'tipo' => 'original_name',
            default => 'created_at',
        };

        return $query
            // ⚠️ reorder(), NOT orderBy(). Client::brandAssets() is declared
            // `->latest()`, so an appended order is a SECOND key behind
            // created_at and never gets a say — except when two rows share a
            // timestamp, which is exactly what factory-made rows do. That is
            // how this shipped green: the tests tied on created_at and fell
            // through to the real sort, while the browser never did.
            ->reorder()
            ->orderBy($column, $this->direction($request))
            // A stable tiebreak, so two files uploaded in the same second do
            // not swap places between reloads and look like the list moved.
            ->orderBy('id', 'desc');
    }

    private function sort(Request $request): string
    {
        $sort = (string) $request->query('orden', 'fecha');

        return in_array($sort, ['fecha', 'nombre', 'peso', 'tipo'], true) ? $sort : 'fecha';
    }

    private function direction(Request $request): string
    {
        return $request->query('dir') === 'asc' ? 'asc' : 'desc';
    }
}
