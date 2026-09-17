<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder somebody sorts their own conversations into — item 5.
 *
 * ⚠️ SCOPED EXACTLY LIKE THE CONVERSATIONS IT HOLDS: per person, per surface,
 * and per brand on the portal. A folder scoped any wider appears empty when
 * somebody switches brands, which reads as lost work rather than as a filter.
 *
 * ⚠️ DELETING ONE NEVER DELETES WHAT IS IN IT. `conversations.folder_id` is
 * nullOnDelete, so the conversations fall back to the unfiled list. Losing a
 * month of work by tidying up is the most expensive mistake this panel could
 * allow, and it is one careless click away from an ordinary one.
 *
 * @see database/migrations/..._create_conversation_folders_table.php
 */
class ConversationFolder extends Model
{
    protected $fillable = [
        'user_id',
        'surface',
        'client_id',
        'name',
        'position',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'folder_id');
    }

    /**
     * This person's folders on this surface, in the order they arranged them.
     *
     * ⚠️ `position`, NOT `name`. Alphabetical is not what anybody means by "my
     * folders" — the one they use every day belongs at the top whatever it is
     * called.
     */
    public function scopeFor(Builder $query, int $userId, string $surface, ?int $clientId = null): Builder
    {
        return $query->where('user_id', $userId)
            ->where('surface', $surface)
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId))
            ->orderBy('position')
            ->orderBy('id');
    }

    /** Whether this folder is that person's to open, rename or drop into. */
    public function isReachableBy(?User $user): bool
    {
        return $user !== null && $user->getKey() === $this->user_id;
    }
}
