<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContextDocumentRequest;
use App\Models\Client;
use App\Models\ContextDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContextDocumentController extends Controller
{
    /** The private disk these live on. Never web-accessible. */
    private const DISK = 'local';

    public function store(StoreContextDocumentRequest $request, Client $client): RedirectResponse
    {
        $file = $request->file('file');

        // Laravel generates the stored filename, so a hostile original name
        // never reaches the filesystem. The real name is kept in the DB only.
        $path = $file->store("context/{$client->id}", self::DISK);

        $client->contextDocuments()->create([
            'uploaded_by' => $request->user()->id,
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'kind' => $request->validated('kind'),
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return back()->with('status', 'Archivo de contexto subido.');
    }

    public function download(Client $client, ContextDocument $document): StreamedResponse
    {
        // Guard against /clientes/marca-a/contexto/{id-belonging-to-marca-b}
        abort_unless($document->client_id === $client->id, 404);

        abort_unless(
            Storage::disk($document->disk)->exists($document->path),
            404,
        );

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->original_name,
        );
    }

    public function destroy(Client $client, ContextDocument $document): RedirectResponse
    {
        abort_unless($document->client_id === $client->id, 404);

        // The model's deleted() hook removes the file from disk.
        $document->delete();

        return back()->with('status', 'Archivo eliminado.');
    }
}
