<?php

namespace App\Models;

use App\Services\Ai\Data\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the brand-onboarding conversation.
 *
 * @see database/migrations/..._create_brand_onboarding_messages_table.php
 */
class BrandOnboardingMessage extends Model
{
    protected $fillable = [
        // Which conversation this turn belongs to - item 5.
        'conversation_id',
        'client_id',
        'user_id',
        'role',
        'body',
        'proposals',
        'questions',
        'attachments',
        'attachment_ids',
    ];

    protected function casts(): array
    {
        return [
            'proposals' => 'array',
            'questions' => 'array',
            'attachments' => 'array',
            // Which assets those names became, positionally. Null for every
            // turn from before the files were kept — see the migration.
            'attachment_ids' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * The tail of the thread, oldest first.
     *
     * Capped because the transcript is replayed into every request and grows
     * without limit otherwise: a brand filled over three weeks would eventually
     * send a month of chat to read one new PDF. The cap is generous enough that
     * a working session never notices it, and the form state — which is what
     * actually matters for continuity — is rebuilt from the database on every
     * turn regardless of how much history survives.
     */
    public function scopeThread(Builder $query, int $clientId, int $limit = 20): Builder
    {
        return $query->where('client_id', $clientId)
            ->orderByDesc('id')
            ->limit($limit);
    }

    /**
     * This row as a message the model can read.
     *
     * An assistant turn replays with the field keys it proposed appended to it.
     * Without them the model sees only its own prose and has no memory of what
     * it already put forward, so it proposes the same twenty answers again on
     * the next message — the values, not the base64, which is why the full JSON
     * is not replayed.
     */
    public function toLlmMessage(): Message
    {
        if (! $this->isFromAssistant()) {
            return Message::user($this->body);
        }

        $proposed = array_filter(array_map(
            static fn (mixed $p): string => is_array($p) ? (string) ($p['field'] ?? '') : '',
            $this->proposals ?? [],
        ));

        return Message::assistant($proposed === []
            ? $this->body
            : $this->body."\n\n[Campos ya propuestos en este turno: ".implode(', ', $proposed).']');
    }
}
