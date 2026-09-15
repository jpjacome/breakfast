<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The end of context documents. A brand has one kind of file again.
 *
 * They were the material the assistant would read to learn a brand's voice —
 * briefs, brandbooks, workshop notes — kept apart from brand assets because
 * one went IN to the model and the other OUT to the client.
 *
 * That reason stopped being true. The assistant's context is the 48
 * entregables and the brand's own details, read from the database; a document
 * only ever reached it as plain text, and brandbooks arrive as PDFs, so in
 * practice these files fed nothing. Meanwhile the team was uploading files
 * here that the client was meant to see and could not. Two boxes, one of them
 * a dead end.
 *
 * ⚠️ THE ROWS MOVE, THE FILES DO NOT. A brand asset carries its own disk and
 * path, and nothing requires that path to sit under marcas/{slug}/assets —
 * BrandAsset::folderFor() only decides where NEW uploads land. So each
 * document becomes an asset pointing at the bytes already on disk, and
 * context_documents is emptied with the query builder rather than through
 * Eloquent: the model's deleted() hook removes the file, which is exactly what
 * must not happen here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('context_documents')) {
            $documents = DB::table('context_documents')->orderBy('id')->get();

            foreach ($documents as $document) {
                DB::table('brand_assets')->insert([
                    'client_id' => $document->client_id,
                    'uploaded_by' => $document->uploaded_by,
                    // The description is dropped rather than folded into the
                    // title: an asset's title is what the client reads in a
                    // list, and "Brief 2026 — lo que mandó el cliente en mayo"
                    // is a note to Breakfast, not a filename.
                    'title' => $document->title,
                    'disk' => $document->disk,
                    'path' => $document->path,
                    'original_name' => $document->original_name,
                    'mime' => $document->mime,
                    'size_bytes' => $document->size_bytes,
                    'created_at' => $document->created_at,
                    'updated_at' => $document->updated_at,
                ]);
            }

            // Query builder: Eloquent would fire ContextDocument::deleted() and
            // delete the file the new asset row now points at.
            DB::table('context_documents')->delete();
        }

        Schema::dropIfExists('context_documents');
    }

    /**
     * Deliberately not reversible.
     *
     * Recreating the table is easy; deciding which of a brand's assets used to
     * be a context document is not, and guessing would move a client's logo
     * pack into a folder the client cannot see. Roll forward.
     */
    public function down(): void
    {
        //
    }
};
