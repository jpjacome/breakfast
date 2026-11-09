<?php

declare(strict_types=1);

namespace App\Services\BrandEgg;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Models\BrandEggMessage;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Attachment;
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
    public function answer(
        Client $client,
        User $user,
        string $question,
        ?BrandEggLayer $layer,
        array $attachments = [],
    ): array {
        /*
         * ⚠️ FILES RIDE ON THE LAST TURN, NEVER ABOVE IT. One in the cacheable
         * prefix is a new prefix every request, and a photo is the most
         * expensive thing that could go there (CLAUDE.md §7). The same rule the
         * other three assistants follow.
         */
        $messages = [
            Message::cacheableSystem((string) config('ai.egg_assistant_prompt')),
            ...$this->thread($client, $layer),
            Message::userWithAttachments($this->turn($client, $layer, $question), $attachments),
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

        $this->remember($client, $user, $question, $reply, $layer, $attachments);

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
    /**
     * The brief for this turn: the objectives of the section, which one is on
     * the table, and what is forbidden.
     *
     * ⚠️ IT IS A SPECIFICATION, NOT CONTEXT. Written as prose it was read as
     * suggestion: she invented a section that does not exist, asked for an
     * entregable belonging to another one, and announced a section finished
     * while the checklist said otherwise. Everything here is stated once,
     * unambiguously, in the order it has to be obeyed.
     *
     * ⚠️ THE OBJECTIVES COME FROM THE ENUM, not from her. Without a written
     * objective per entregable she improvises a question from the LABEL —
     * "Brand promise" is not a question — and then invents somewhere to put the
     * answer. See BrandEggLayer::objective().
     */
    private function turn(Client $client, ?BrandEggLayer $layer, string $question): string
    {
        if ($layer === null) {
            return implode("\n", [
                'Marca: '.$client->name,
                '',
                'Las cinco secciones del Brand Egg, en orden, y qué va en cada una:',
                $this->sectionNames(),
                '',
                'Todavía no están trabajando en una sección concreta. Si preguntan por dónde '
                    .'empezar, la respuesta es la sección 1.',
                '',
                'MENSAJE DEL EQUIPO:',
                trim($question),
            ]);
        }

        $progress = LayerProgress::for($client, $layer);
        $next = $progress->next();

        $lines = [];

        // 1. The job, before any context at all.
        $lines[] = '=== TU TRABAJO EN ESTE MENSAJE ===';

        if ($layer->isInventory()) {
            $lines[] = 'Esta sección no se escribe: es el inventario de archivos de la marca.';
        } elseif ($next === null) {
            $lines[] = 'No falta ningún entregable en esta sección. Puedes proponer cerrarla.';
        } else {
            $lines[] = 'OBJETIVO: conseguir «'.$next->label().'».';
            $lines[] = 'QUÉ ES: '.(BrandEggLayer::objective($next) ?? $next->label());
            $lines[] = 'CÓMO: si todavía no te lo han respondido, pregúntalo. Si ya te lo '
                .'respondieron, devuélveselo en una frase y envuélvelo en ⟦guardar⟧…⟦/guardar⟧.';
            $lines[] = '';
            $lines[] = 'PROHIBIDO EN ESTE MENSAJE:';
            $lines[] = '- Preguntar tú por otra cosa: la pregunta que hagas es ésta.';
            $lines[] = '- Decir que esta sección está lista, resumirla o proponer pasar a otra.';
            $lines[] = '- Nombrar secciones o entregables que no estén en esta lista.';
            $lines[] = '- Repetir lo que dijiste en tu mensaje anterior.';
            $lines[] = '';
            // ⚠️ THE LINE ABOVE GOVERNS WHAT SHE ASKS, NOT WHETHER SHE ANSWERS.
            // Without this she read "no preguntes por otra cosa" as licence to
            // ignore the team: asked whether an image could be filed as an
            // asset, she answered "eso va en Brand Assets y aquí estamos
            // armando la yema" and went straight back to her question. The
            // objective is why she asks, never a reason to leave somebody
            // unanswered.
            $lines[] = 'Si el equipo te dijo o preguntó otra cosa, contéstala primero en una '
                .'línea y sigue con el objetivo en el mismo mensaje. Contestar no es cambiar '
                .'de objetivo.';
            $lines[] = 'Y si lo que te dijeron es trabajo de OTRA sección, dilo, nombra cuál y '
                .'ofréceles cambiarse. Ellos la cambian arriba; tú no puedes.';
        }

        // 2. Where we are.
        $lines[] = '';
        $lines[] = '=== DÓNDE ESTAMOS ===';
        $lines[] = 'Marca: '.$client->name;
        $lines[] = 'Sección '.$layer->ring().' de 5: '.$layer->label();
        $lines[] = $layer->description();
        $lines[] = '';
        $lines[] = 'Las cinco secciones, en orden, y qué va en cada una:';
        $lines[] = $this->sectionNames();

        // 3. The objectives of this section, and their state.
        if (! $layer->isInventory()) {
            $lines[] = '';
            $lines[] = '=== OBJETIVOS DE ESTA SECCIÓN ===';
            $lines[] = 'Estado leído de la base de datos. Es el único que cuenta: lo que hayas '
                .'propuesto tú no está guardado hasta que una persona hace clic.';
            $lines[] = $progress->toMarkdown();
        }

        $lines[] = '';
        $lines[] = '=== MENSAJE DEL EQUIPO ===';
        $lines[] = trim($question);

        return implode("\n", $lines);
    }

    /**
     * The five sections, named the same way every turn so none gets invented —
     * and each with what belongs in it.
     *
     * ⚠️ THE DESCRIPTIONS ARE WHAT LET HER SEE SHE IS IN THE WRONG SECTION.
     * With names alone she could not tell that "¿agrego esta imagen?" is
     * section 4's business, so she read it as an interruption to section 1 and
     * refused it. A person doing this work moves between rings constantly; the
     * assistant has to recognise that and offer to move, not hold the line.
     *
     * ⚠️ FROM THE ENUM, never typed into the prompt. It is the same bytes for
     * every brand and would be cheaper in block 1, but a copy in a config file
     * drifts the day a label changes and nothing would fail — the map would
     * just quietly describe an app that no longer exists.
     */
    private function sectionNames(): string
    {
        return collect(BrandEggLayer::cases())
            ->map(fn (BrandEggLayer $layer) => '- '.$layer->ring().'. '
                .$layer->label().' — '.$layer->description())
            ->implode('
');
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
        array $attachments = [],
    ): void {
        BrandEggMessage::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $question,
            'layer' => $layer,
            // ⚠️ NAMES, NEVER BYTES. An earlier turn replays its files by name;
            // re-inlining a screenshot on every later question would multiply a
            // conversation's cost by the size of its first image.
            'attachments' => array_map(
                static fn (Attachment $file): string => $file->filename,
                $attachments,
            ) ?: null,
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
