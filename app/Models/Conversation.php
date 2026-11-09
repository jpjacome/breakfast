<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One conversation with an assistant — item 5.
 *
 * ⚠️ IT BELONGS TO A PERSON, like the turns in it. `AssistantMessage` is keyed
 * on `user_id` + `surface` precisely so no query in the app returns somebody
 * else's conversation, and putting a boundary around those turns must not open
 * a door around the side. Every read here goes through `scopeFor()`.
 *
 * ⚠️ THE EGG'S CONVERSATION IS THE EXCEPTION AND IS NOT THIS. `brand_egg_messages`
 * is keyed on the brand, because building an Egg is team work — see that
 * table's migration. It gets conversations too, scoped the same way it is.
 *
 * @see database/migrations/..._create_conversations_table.php
 */
class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'surface',
        'client_id',
        'title',
        'folder_id',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** What brand it is about, where that means anything. See the migration. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    /** The folder somebody dragged it into, or none. */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ConversationFolder::class, 'folder_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether this conversation is that person's to read, continue or rename.
     *
     * ⚠️ ALL THREE QUESTIONS AT ONCE, and every one of them fails closed. The
     * owner is the obvious one; the surface stops a dashboard id being posted
     * to Brandy's endpoint, where it would replay Breakfast's portfolio turns
     * into a client's prompt; and the brand stops one brand's thread being
     * continued while another is selected (ACC-01). A soft-deleted row is
     * already excluded by the global scope.
     *
     * ⚠️ `$clientId` IS COMPARED EXACTLY, null included. A dashboard
     * conversation is stored with none, so passing a brand in must not match
     * one — that is the check, not an oversight.
     */
    public function isReachableBy(?User $user, string $surface, ?int $clientId = null): bool
    {
        return $user !== null
            && $user->getKey() === $this->user_id
            && $surface === $this->surface
            && $clientId === $this->client_id;
    }

    /**
     * Say that something happened in it.
     *
     * ⚠️ THE PANEL IS ORDERED BY `updated_at` AND NOTHING ELSE TOUCHES IT.
     * Turns are rows in another table, so answering a question changes nothing
     * on this row — without this call the history would be sorted by when each
     * conversation was STARTED, and the one being used right now would sink
     * down the list as older ones were opened.
     */
    public function noteActivity(): void
    {
        $this->forceFill(['updated_at' => now()])->save();
    }

    /**
     * This person's conversations on this surface, newest activity first.
     *
     * ⚠️ `$client` SCOPES THE PORTAL AND MUST NOT SCOPE THE DASHBOARD. Brandy's
     * thread is per brand — switching brands must switch the list, or one
     * brand's conversation titles show up while looking at another. The
     * dashboard assistant crosses brands by design and is asked without it.
     */
    public function scopeFor(Builder $query, int $userId, string $surface, ?int $clientId = null): Builder
    {
        return $query->where('user_id', $userId)
            ->where('surface', $surface)
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId))
            // Archived is "I am done with this", not "delete it". Out of the
            // list by default, whole and one click away — see scopeArchived().
            ->whereNull('archived_at')
            ->orderByDesc('updated_at');
    }

    /** The same list, the other half: what was put away. */
    public function scopeArchived(Builder $query, int $userId, string $surface, ?int $clientId = null): Builder
    {
        return $query->where('user_id', $userId)
            ->where('surface', $surface)
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId))
            ->whereNotNull('archived_at')
            ->orderByDesc('updated_at');
    }

    /**
     * The turns the model should actually read.
     *
     * ⚠️ NOT "THE LAST N". That was the old behaviour and it is what item 5
     * objects to: past twenty turns the model silently stopped knowing the
     * beginning, with nothing said and nothing kept. Here the early part is
     * folded into `summary` and what is returned is everything since — so
     * nothing is forgotten, it is carried differently.
     *
     * @return Collection<int, AssistantMessage>
     */
    public function replayable()
    {
        return $this->messages()
            ->when(
                $this->summarised_through_id !== null,
                fn (Builder $q) => $q->where('id', '>', $this->summarised_through_id),
            )
            ->get();
    }

    /**
     * Name it from the first thing that was asked.
     *
     * ⚠️ NOT ASKED FOR, AND NOT GENERATED. Nobody titles a chat before having
     * it, and spending an API call to name one is a paid request that answers
     * no question. The first question is what the person came to ask, so it is
     * already the best short description of the conversation there is.
     *
     * Only ever set once: a conversation that wanders is still the one that
     * started here, and a title that moves makes the history unrecognisable.
     */
    public function titleFrom(string $question): void
    {
        if ($this->title !== null || trim($question) === '') {
            return;
        }

        $this->forceFill(['title' => Str::limit(trim($question), 60)])->save();
    }
}
