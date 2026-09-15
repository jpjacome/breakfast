<?php

declare(strict_types=1);

namespace App\Services\BrandEgg;

use App\Enums\BrandEggLayer;
use App\Models\Client;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\UsageRecorder;

/**
 * Composes one layer of a brand's Brand Egg out of the entregables it reads.
 *
 * THE ONLY PLACE A LAYER IS BUILT. A screen, a route and a prompt builder each
 * carrying their own idea of what feeds a layer would drift, and the Egg would
 * end up composed from one set of entregables and explained by another — which
 * is why BrandEggLayer::sources() is the single list and why nothing here
 * accepts an override of it.
 *
 * Message layout:
 *
 *   ┌─ system: the composer's instructions ─┐  identical for EVERY layer of
 *   │                                          EVERY brand, forever
 *   └─ user: this layer, and its sources ───┘  volatile, always last
 *
 * ⚠️ TWO MESSAGES, AND THE LAYER'S NAME IS IN THE SECOND ONE. Putting "you are
 * writing the Personalidad layer" in the system block reads more naturally and
 * would give each of the five calls its own prefix: five paid readings of the
 * same instructions per brand, every time somebody clicks Componer todo. Same
 * mistake the two-phase brandbook read exists to avoid (CLAUDE.md §7).
 *
 * ⚠️ FIVE CALLS, NOT ONE. A single generation producing all five layers is the
 * 60–150s request that this host kills at ~182s while starving the worker pool
 * the public site shares (CLAUDE.md §3). Each call here is short.
 *
 * ⚠️ WHAT "PROPOSAL, NEVER A WRITE" MEANS HERE, because docs/brand-egg.md §6
 * says it and §5 appears to contradict it. It is about the ENTREGABLES: a
 * composition never touches brand_deliverables, not one column, and there is a
 * test that pins the row byte-identical across a run. The composed layer itself
 * IS written to brand_eggs — it has to be, or "sin aprobar" could not exist as
 * a state — and the human review it is waiting for is APPROVAL, which is the
 * thing that decides whether the brand ever sees it (BrandEggState).
 */
final class EggComposer
{
    /**
     * When to stop starting layers, in seconds since the run began.
     *
     * ⚠️ NOT A TIMEOUT. AI_TIMEOUT=90 bounds one call; nothing bounds five in
     * a row except this. Five at ~20s is ~100s and the host kills the request
     * at ~182s, so the budget is the guard against the run that is slower than
     * usual: past it the composer stops rather than starting a call it cannot
     * finish. 150 leaves room for the response of the call already in flight.
     */
    private const BUDGET_SECONDS = 150;

    public function __construct(
        private readonly LlmClient $client,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Compose one layer and save it. Returns the text, or null when there was
     * nothing to compose from.
     *
     * ⚠️ ALL SOURCES EMPTY MEANS NO CALL AT ALL. An empty layer is honest; a
     * layer of hedging is noise, and paying a provider to write the hedging is
     * worse. docs/brand-egg.md §2 Finding 3.
     *
     * @throws LlmException
     */
    public function compose(Client $client, BrandEggLayer $layer, ?int $userId = null): ?string
    {
        $sources = $this->sourceBlock($client, $layer);

        if ($sources === null) {
            return null;
        }

        $dependency = $this->dependencyBlock($client, $layer);

        $messages = [
            // The cached prefix. Marked once, and it is the LAST stable block,
            // which on Gemini caches everything above it with it. On DeepSeek
            // cacheableSystem() returns the same plain string, so the bytes are
            // unchanged and the exact-match prefix cache still hits.
            Message::cacheableSystem($this->instructions()),
            Message::user($this->layerTurn($client, $layer, $sources, $dependency)),
        ];

        try {
            $response = $this->client->complete($messages, role: 'content', options: [
                // The floor. This is synthesis of material a person already
                // reviewed, not copywriting: the default 0.8 is set for brand
                // copy and here it would invent an attribute into the brand's
                // primary memory.
                'temperature' => 0.1,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $client->id,
                userId: $userId,
                operation: 'brand-egg-compose',
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $client->id,
            userId: $userId,
            operation: 'brand-egg-compose',
        );

        $text = $this->clean($response->content);

        if ($text === '') {
            return null;
        }

        $this->save($client, $layer, $text);

        return $text;
    }

    /**
     * Compose every layer that has something to compose from, in dependency
     * order, saving each as it lands.
     *
     * ⚠️ SAVED ONE BY ONE, NOT ALL FIVE AT THE END. A run that dies on layer 4
     * leaves layers 1, 2 and 3 written and the drawing showing which rings are
     * still hollow — rather than a hundred seconds spent and nothing kept.
     *
     * ⚠️ AND IT STOPS ITSELF. See BUDGET_SECONDS: past the budget the run ends
     * where it is, which is a partial Egg somebody can finish ring by ring
     * instead of a 502 with no trace (CLAUDE.md trap 5).
     *
     * @return array{composed: array<int, string>, skipped: array<int, string>, stopped: bool}
     */
    public function composeAll(Client $client, ?int $userId = null): array
    {
        $started = microtime(true);
        $composed = [];
        $skipped = [];
        $stopped = false;

        foreach ($this->order() as $layer) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                $stopped = true;
                break;
            }

            if ($this->compose($client, $layer, $userId) === null) {
                $skipped[] = $layer->value;

                continue;
            }

            $composed[] = $layer->value;
        }

        return ['composed' => $composed, 'skipped' => $skipped, 'stopped' => $stopped];
    }

    /**
     * The five layers, each after anything it depends on.
     *
     * Derived from dependsOn() rather than written out, so the day layer 5
     * stops reading layer 2 (docs/brand-egg.md §2 Finding 1 — Breakfast has not
     * answered yet) the order follows the enum instead of having to be
     * remembered here too.
     *
     * @return array<int, BrandEggLayer>
     */
    public function order(): array
    {
        $ordered = [];
        $placed = [];

        // Two passes rather than a general topological sort: the graph is five
        // nodes with at most one edge, and a sort would be more machinery than
        // the problem has.
        foreach (BrandEggLayer::cases() as $layer) {
            if ($layer->dependsOn() === null) {
                $ordered[] = $layer;
                $placed[$layer->value] = true;
            }
        }

        foreach (BrandEggLayer::cases() as $layer) {
            if (! isset($placed[$layer->value])) {
                $ordered[] = $layer;
            }
        }

        return $ordered;
    }

    /* ---------------------------------------------------------------------
       Writing
       --------------------------------------------------------------------- */

    /**
     * One layer onto the brand's Egg, creating the row if this is its first.
     *
     * generated_at moves on every composition, including a recomposition of a
     * single ring: it answers "has anything ever been composed", which is the
     * first question BrandEggState asks, and a recompose is still a generation.
     *
     * ⚠️ approved_at IS DELIBERATELY LEFT ALONE. Recomposing a ring after
     * approval does not un-approve the Egg and must not: the state that then
     * shows is Desactualizado, derived from the entregables having moved, and
     * clearing the stamp would throw away the record of who signed it off and
     * when. Approving again is one click and re-stamps.
     */
    private function save(Client $client, BrandEggLayer $layer, string $text): void
    {
        $egg = $client->brandEggOrNew();

        $egg->fill([
            $layer->value => $text,
            'generated_at' => now(),
        ]);

        $client->brandEgg()->save($egg);

        // The relation is cached on the model, and the next read of it — the
        // state badge, the next layer's dependency block — would be the stale
        // pre-save instance without this.
        $client->setRelation('brandEgg', $egg);
    }

    /* ---------------------------------------------------------------------
       Prompt blocks
       --------------------------------------------------------------------- */

    /** Block 1. Never interpolate anything into this — see the class docblock. */
    private function instructions(): string
    {
        return (string) config('ai.egg_composer_prompt');
    }

    /**
     * The entregables this layer reads, verbatim, or null when not one of them
     * has been written.
     *
     * Verbatim and not truncated: this is the one call in the app whose whole
     * job is to read these texts closely, and a synthesis of a summary is two
     * lossy steps where one was asked for.
     *
     * An empty source is NAMED rather than omitted — the same rule as
     * BrandDeliverables::toMarkdown(), and for the same reason: silence gets
     * completed with whatever is most probable for the category, while an
     * explicit "NO DEFINIDO" does not.
     */
    private function sourceBlock(Client $client, BrandEggLayer $layer): ?string
    {
        $deliverables = $client->deliverablesOrNew();

        $lines = [];
        $anyFilled = false;

        foreach ($layer->sources() as $item) {
            if ($deliverables->has($item)) {
                $lines[] = "**{$item->label()}**\n".$deliverables->value($item);
                $anyFilled = true;

                continue;
            }

            $lines[] = "**{$item->label()}**\nNO DEFINIDO";
        }

        // Every source empty: nothing to synthesise, so nothing is asked for.
        // The dependency does not rescue it — a layer built on another layer
        // alone would be a restatement, not a synthesis.
        return $anyFilled ? implode("\n\n", $lines) : null;
    }

    /**
     * The text of the layer this one reads the OUTPUT of, when there is one.
     *
     * ⚠️ MISSING IS SAID, NOT SKIPPED SILENTLY. Layer 5 reads layer 2's result,
     * and composing 5 while 2 is empty is a real thing to do — the person asked
     * for one ring. What must not happen is layer 5 being written as though it
     * had read a Personalidad that does not exist, so the absence goes in the
     * turn under the same NO DEFINIDO the entregables use.
     */
    private function dependencyBlock(Client $client, BrandEggLayer $layer): ?string
    {
        $parent = $layer->dependsOn();

        if ($parent === null) {
            return null;
        }

        $egg = $client->brandEggOrNew();

        return "**{$parent->label()}** (capa {$parent->ring()} de este mismo Brand Egg)\n"
            .($egg->has($parent) ? $egg->text($parent) : 'NO DEFINIDO');
    }

    /**
     * Block 3: which layer, and the text it is composed from.
     *
     * Everything per-layer and per-brand is here, below the cached prefix. The
     * brand's NAME is here too and it matters that it is: it is the most
     * obviously per-brand string in the whole call, and one line of it in the
     * system block would give every brand its own prefix.
     */
    private function layerTurn(
        Client $client,
        BrandEggLayer $layer,
        string $sources,
        ?string $dependency,
    ): string {
        $turn = 'MARCA: '.$client->name
            ."\n\nCAPA QUE TE TOCA ESCRIBIR: {$layer->label()} (capa {$layer->ring()} de 5)\n"
            .$layer->description()
            ."\n\n---\n\nENTREGABLES DE LOS QUE SE SINTETIZA ESTA CAPA.\n"
            ."Esto es todo lo que tienes. Lo que no esté aquí, no existe para este mensaje:\n\n"
            .$sources;

        if ($dependency !== null) {
            $turn .= "\n\n---\n\nADEMÁS, ESTA CAPA LEE EL RESULTADO DE OTRA CAPA DEL BRAND EGG.\n"
                ."No la repitas: parte de ella y di lo que esta capa añade.\n\n"
                .$dependency;
        }

        return $turn."\n\n---\n\nEscribe ahora el párrafo de la capa «{$layer->label()}».";
    }

    /**
     * Strip what the model adds despite being asked not to.
     *
     * Not a formatter and not a rescue: the prompt asks for one bare paragraph,
     * and this takes off the two things a model reaches for anyway — a markdown
     * heading and surrounding quotes — so that a stray "**Esencia**" does not
     * end up saved as part of the brand's memory and read back later as though
     * the brand had written it. Anything else it returns is left exactly as it
     * came, because silently rewriting a synthesis is how you stop being able
     * to trust what is in the column.
     */
    private function clean(string $text): string
    {
        $text = trim($text);
        $text = (string) preg_replace('/^#{1,6}\s*.*\R+/u', '', $text);
        $text = trim($text);

        if (mb_strlen($text) > 1 && str_starts_with($text, '"') && str_ends_with($text, '"')) {
            $text = trim(mb_substr($text, 1, -1));
        }

        return $text;
    }
}
