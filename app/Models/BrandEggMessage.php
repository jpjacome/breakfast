<?php

namespace App\Models;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Services\Ai\Data\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the conversation that builds a brand's Brand Egg.
 *
 * ⚠️ THE THREAD BELONGS TO THE BRAND, NOT TO WHOEVER TYPED. See the migration:
 * two Breakfast people build one Egg over a fortnight, so scoping this to a
 * user would hide half the work from the other half of the team.
 *
 * @see database/migrations/..._create_brand_egg_messages_table.php
 * @see docs/brand-egg.md §14
 */
class BrandEggMessage extends Model
{
    protected $fillable = [
        'client_id',
        'user_id',
        'role',
        'body',
        'layer',
        'proposals',
        'questions',
    ];

    protected function casts(): array
    {
        return [
            'layer' => BrandEggLayer::class,
            'proposals' => 'array',
            'questions' => 'array',
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
     * The tail of the thread, newest first — reverse it to replay.
     *
     * Capped for the reason BrandOnboardingMessage::scopeThread() gives: the
     * transcript rides in every request and grows without limit otherwise, so
     * a brand filled over three weeks would eventually send a month of chat to
     * answer one question. The checklist is rebuilt from the database on every
     * turn regardless of how much history survives, which is what actually
     * carries continuity here.
     */
    public function scopeThread(Builder $query, int $clientId, int $limit = 20): Builder
    {
        return $query->where('client_id', $clientId)
            ->orderByDesc('id')
            ->limit($limit);
    }

    /**
     * The entregables this turn PROPOSED something for.
     *
     * ⚠️ PROPOSED, NOT WROTE. A proposal is a card; the column moves when
     * somebody clicks it, through the one-entregable route. So this answers
     * "has the assistant already put this forward", which is what the replay
     * in toLlmMessage() needs to stop it proposing the same thing twice.
     *
     * @return array<int, DeliverableItem>
     */
    public function proposedItems(): array
    {
        return $this->itemsIn($this->proposals);
    }

    /**
     * The entregables this turn ASKED about.
     *
     * ⚠️ THIS IS WHAT MAKES ➖ "no aplica" DERIVABLE. An optional entregable
     * that is empty AND has been asked about is one the team has been offered
     * and declined; an optional nobody has raised yet is simply not done. The
     * two look identical in `brand_deliverables` — both are null — so the
     * difference has to be read from the conversation. That is the whole reason
     * the checklist can reach "done" without a status column (CLAUDE.md §8
     * rule 2).
     *
     * @return array<int, DeliverableItem>
     */
    public function askedItems(): array
    {
        return $this->itemsIn($this->questions);
    }

    /**
     * Whichever of the 48 a json column names, ignoring anything else in it.
     *
     * ⚠️ Unknown keys are DROPPED, not trusted. The enum is the vocabulary, so
     * a stale key left by an older shape of the payload cannot become a column
     * name or a tick — the same argument that binds {item} to the enum on the
     * write route (docs/brand-egg.md §14.12).
     *
     * @param  array<int|string, mixed>|null  $list
     * @return array<int, DeliverableItem>
     */
    private function itemsIn(?array $list): array
    {
        $items = [];

        foreach ($list ?? [] as $entry) {
            $key = match (true) {
                is_array($entry) => (string) ($entry['entregable'] ?? ''),
                is_string($entry) => $entry,
                default => '',
            };

            $item = DeliverableItem::tryFrom($key);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * This row as a message the model can read.
     *
     * An assistant turn replays with the entregables it proposed appended,
     * for the reason BrandOnboardingMessage documents: without them the model
     * sees only its own prose, has no memory of what it already put forward,
     * and proposes the same thing again on the next message. The KEYS only —
     * the proposed text is already in the body it is replaying.
     */
    public function toLlmMessage(): Message
    {
        if (! $this->isFromAssistant()) {
            return Message::user($this->body);
        }

        $proposed = array_map(
            static fn (DeliverableItem $item): string => $item->value,
            $this->proposedItems(),
        );

        return Message::assistant($proposed === []
            ? $this->body
            : $this->body."\n\n[Entregables ya propuestos en este turno: ".implode(', ', $proposed).']');
    }
}
