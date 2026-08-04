<?php

namespace App\Models;

use App\Enums\ContextDocumentKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ContextDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'uploaded_by',
        'title',
        'description',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ContextDocumentKind::class,
            'size_bytes' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the stored object and the row in step. Without this, deleting
        // a document leaves an orphaned file on disk forever.
        static::deleted(function (ContextDocument $doc) {
            Storage::disk($doc->disk)->delete($doc->path);
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

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
}
