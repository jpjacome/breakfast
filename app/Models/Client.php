<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'industry',
        'status',
        'contact_name',
        'contact_email',
        'notes',
        'onboarded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'onboarded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (blank($client->slug)) {
                $client->slug = static::uniqueSlug($client->name);
            }
        });
    }

    /**
     * Slugs are used in URLs and must stay unique across soft-deleted rows
     * too, otherwise restoring a client collides with a live one.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function contextDocuments(): HasMany
    {
        return $this->hasMany(ContextDocument::class)->latest();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Two-letter monogram for the avatar tile. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '·';
    }
}
