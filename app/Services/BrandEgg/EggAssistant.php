<?php

declare(strict_types=1);

namespace App\Services\BrandEgg;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\BrandEggMessage;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\UsageRecorder;

/**
 * The conversation that co-creates a brand's Brand Egg — step 1 of §1.
 *
 * ⚠️ IT IS NOT A BIGGER EggComposer. That one reads entregables and writes a
 * paragraph; it cannot help a brand that has none, and that is now the normal
 * case because Breakfast inverted the flow — the Egg is built FIRST and the
 * toolkit comes after. This is the half that ASKS.
 *
 * Message layout, and the reason for it:
 *
 *   ┌─ system: the assistant's instructions ─┐  identical for EVERY brand,
 *   │                                           layer and mode, FOREVER
 *   ├─ …the thread for this layer ───────────┤  grows, extends the prefix
 *   └─ user: the checklist + this turn ──────┘  volatile, always last
 *
 * ⚠️ WHICH LAYER AND WHICH MODE GO IN THE USER TURN. Putting "estás en la capa
 * Personalidad" in the system block reads more naturally and would give each of
 * the five layers its own cached prefix — five paid readings of the same
 * instructions per brand. Same mistake the two-phase brandbook read exists to
 * avoid (CLAUDE.md §7).
 *
 * ⚠️ AND THE CHECKLIST IS VOLATILE, so it belongs there too: it changes the
 * moment anybody accepts a card.
 *
 * @see docs/brand-egg.md §14 — every rule in the prompt, with its reasoning.
 */
final class EggAssistant
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * One turn.
     *
     * ⚠️ BOTH HALVES ARE WRITTEN AFTER THE ANSWER LANDS, never before. A
     * question stored with no answer replays as the assistant ignoring
     * somebody — the same rule as `AssistantMessage` (CLAUDE.md §7).
     *
     * @return array{reply: string, layer: ?BrandEggLayer}
     *
     * @throws LlmException the caller turns it into a message a person can read
     */
    public function answer(Client $client, User $user, string $question, ?BrandEggLayer $layer): array
    {
        $messages = [
            Message::cacheableSystem((string) config('ai.egg_assistant_prompt')),
            ...$this->thread($client, $layer),
            Message::user($this->turn($client, $layer, $question)),
        ];

        try {
            $response = $this->client->complete($messages, role: 'content', options: [
                // Lower than brand copy, higher than the composer's 0.1: this
                // is a conversation and it should not read like a form, but the
                // proposals in it become brand data once somebody clicks.
                'temperature' => 0.4,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $client->id,
                userId: $user->id,
                operation: 'brand-egg-assistant',
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $client->id,
            userId: $user->id,
            operation: 'brand-egg-assistant',
        );

        $reply = trim($response->content);

        $this->remember($client, $user, $question, $reply, $layer);

        return ['reply' => $reply, 'layer' => $layer];
    }

    /**
     * The turns already had about this layer, oldest first.
     *
     * ⚠️ SCOPED TO THE LAYER, not to the brand's whole Egg conversation. Layer
     * 5's questions have nothing to do with layer 1's, and replaying all of it
     * would bury the layer being worked on under four others — while spending
     * the budget on turns that cannot help. `brand_egg_messages.layer` exists
     * for exactly this.
     *
     * @return array<int, Message>
     */
    private function thread(Client $client, ?BrandEggLayer $layer): array
    {
        return BrandEggMessage::query()
            ->where('client_id', $client->id)
            ->when($layer !== null, fn ($q) => $q->where('layer', $layer->value))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (BrandEggMessage $turn) => $turn->toLlmMessage())
            ->values()
            ->all();
    }

    /**
     * The volatile block: where we are, what is filled, and what was asked.
     *
     * ⚠️ THE CHECKLIST IS RENDERED HERE FROM THE DATABASE, never asked of the
     * model. A model keeping its own tally is right most of the time, and a
     * wrongly ticked entregable is a small lie about whether the brand's
     * promise exists — the one error this app exists to make impossible.
     */
    private function turn(Client $client, ?BrandEggLayer $layer, string $question): string
    {
        $lines = ['Marca: '.$client->name];

        if ($layer !== null) {
            $progress = LayerProgress::for($client, $layer);

            $lines[] = 'Capa '.$layer->ring().' de 5: '.$layer->label();
            $lines[] = $layer->description();
            $lines[] = '';
            $lines[] = $layer->isInventory()
                // No entregable feeds layer 4 — it IS the list of files. Saying
                // so stops her asking for entregables that do not apply.
                ? 'Esta capa no se escribe: es el inventario de archivos de la marca.'
                : "Entregables de esta capa:\n".$progress->toMarkdown();

            if (! $layer->isInventory()) {
                $next = $progress->next();

                $lines[] = '';
                $lines[] = $next === null
                    ? 'No falta ninguno. Puedes cerrar la capa.'
                    : 'El siguiente sin llenar es: '.$next->label()
                        .($next->isRequired() ? ' (obligatorio).' : ' (opcional).');
            }
        } else {
            // A turn about no layer in particular — "¿por dónde empezamos?".
            $lines[] = 'Todavía no están trabajando en una capa concreta.';
        }

        $lines[] = '';
        $lines[] = 'MENSAJE DEL EQUIPO:';
        $lines[] = trim($question);

        return implode("\n", $lines);
    }

    /**
     * Both halves of the turn, after the answer.
     *
     * ⚠️ `questions` RECORDS WHICH ENTREGABLE WAS RAISED, and it is not
     * decoration: `LayerProgress` derives ➖ "no aplica" from an optional that
     * is empty AND has been asked about. Without this write, an optional the
     * team declined stays ⬜ forever and the layer can never finish — which is
     * ERR-07 wearing a checkbox.
     */
    private function remember(
        Client $client,
        User $user,
        string $question,
        string $reply,
        ?BrandEggLayer $layer,
    ): void {
        BrandEggMessage::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $question,
            'layer' => $layer,
        ]);

        BrandEggMessage::create([
            'client_id' => $client->id,
            'role' => 'assistant',
            'body' => $reply,
            'layer' => $layer,
            'questions' => $this->asked($client, $layer, $reply),
        ]);
    }

    /**
     * Which of this layer's entregables the reply actually raised.
     *
     * ⚠️ READ FROM THE REPLY, NOT ASKED FOR AS JSON. Making the model return a
     * structured field alongside its prose is a second thing to get wrong on
     * every turn, and a malformed one would silently stop ➖ ever appearing.
     * Matching the entregable's own label against what she wrote is duller and
     * cannot fail halfway.
     *
     * ⚠️ AND ONLY THIS LAYER'S SOURCES ARE CANDIDATES. She is forbidden to
     * raise anything else (the prompt says so), and scanning for all 48 would
     * let a passing mention of "el tono" mark an entregable as offered on a
     * layer that does not read it.
     *
     * @return array<int, string>
     */
    private function asked(Client $client, ?BrandEggLayer $layer, string $reply): array
    {
        if ($layer === null) {
            return [];
        }

        $haystack = mb_strtolower($reply);

        return array_values(array_map(
            static fn (DeliverableItem $item): string => $item->value,
            array_filter(
                $layer->sources(),
                static fn (DeliverableItem $item): bool => str_contains(
                    $haystack,
                    mb_strtolower($item->label()),
                ),
            ),
        ));
    }
}
