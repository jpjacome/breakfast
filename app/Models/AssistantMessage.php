<?php

namespace App\Models;

use App\Services\Ai\Data\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of a person's own conversation with an assistant.
 *
 * ⚠️ A THREAD BELONGS TO A USER. Every read goes through thread(), which takes
 * a user id and a surface and nothing else, so there is no query in the app
 * that returns somebody else's conversation. An admin asking "¿qué me
 * preguntó el cliente?" gets nothing, because their own thread is the only one
 * that exists as far as this model is concerned.
 *
 * @see database/migrations/..._create_assistant_messages_table.php
 */
class AssistantMessage extends Model
{
    /** Breakfast's dashboard assistant. */
    public const SURFACE_ADMIN = 'admin';

    /** The brand's own assistant, on the client dashboard. */
    public const SURFACE_PORTAL = 'portal';

    protected $fillable = [
        // Which conversation this turn belongs to - item 5.
        'conversation_id',
        'user_id',
        'surface',
        'role',
        'body',
        'attachments',
        'attachment_ids',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            // Which assets those names became, positionally. Null for every
            // turn from before the files were kept — see the migration.
            'attachment_ids' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The brand selected when this was asked, if any. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * The tail of one person's thread, NEWEST first — reverse it to replay.
     *
     * Capped because every turn is replayed into the next request, and an
     * uncapped transcript means a long afternoon's questions are re-sent, and
     * re-paid for, on every new one.
     *
     * Twenty is ten exchanges. It was twelve, and the beta review found the
     * seam: come back after lunch, ask a follow-up, and the turns that gave it
     * meaning had already fallen off the end — so the answer read as if she had
     * forgotten the conversation, which she had. Ten exchanges covers an
     * afternoon's back-and-forth and is still a bounded bill.
     */
    public function scopeThread(
        Builder $query,
        int $userId,
        string $surface,
        int $limit = 20,
        ?int $clientId = null,
    ): Builder {
        return $query->where('user_id', $userId)
            ->where('surface', $surface)
            // ⚠️ ONE THREAD PER BRAND — ACC-01/ACC-03.
            //
            // A thread is replayed into the next request's prompt, so with one
            // account on several brands an unscoped thread would carry brand
            // A's turns into brand B's context. That is the one thing the
            // client's assistant is forbidden to do (CLAUDE.md §7), and it
            // would happen inside a single person's own history, where none of
            // the existing scoping would have caught it.
            //
            // ⚠️ OPT-IN, and it must stay that way. The DASHBOARD thread is one
            // continuous conversation that crosses brands on purpose — it
            // stores the brand that was selected per turn, but reads them all
            // back, because "compará Alea con la otra" is the question it
            // exists to answer. Filtering by default would silently cut that
            // thread into one strand per brand.
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId))
            ->orderByDesc('id')
            ->limit($limit);
    }

    /**
     * This row as a message the model can read.
     *
     * An earlier turn's files are NAMED, not re-sent: the bytes are gone, and
     * re-inlining a screenshot on every subsequent question would multiply the
     * cost of a conversation by the size of its first image. Naming them keeps
     * "¿y el otro color de la captura?" intelligible.
     */
    public function toLlmMessage(): Message
    {
        if ($this->isFromAssistant()) {
            return Message::assistant($this->body);
        }

        $files = $this->attachments ?? [];

        return Message::user($files === []
            ? $this->body
            : trim($this->body.'

[Adjuntó: '.implode(', ', $files).']'));
    }
}
