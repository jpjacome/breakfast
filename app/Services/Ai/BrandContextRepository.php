<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\BrandEggState;
use App\Enums\DeliverableItem;
use App\Enums\ProcessStep;
use App\Models\BrandEgg;
use App\Models\Client;
use App\Services\Ai\Data\BrandContext;

/**
 * Turns a Client into the BrandContext its assistant answers from.
 *
 * This is the ONLY class in the AI layer that touches Eloquent. Everything
 * downstream takes the DTO, so schema changes land here and nowhere else.
 *
 * ⚠️ THE CONTEXT IS THE DATABASE, IN FOUR NUMBERED TIERS:
 *
 *   1. Brand Egg      the brand's primary memory, synthesised and signed off
 *   2. Entregables    the reviewed detail beneath it
 *   3. La marca       the ficha
 *   4. Proceso        where the project stands
 *
 * ⚠️ AND NOTHING ELSE. There is no toolkit tier: the toolkit is the PDF the 48
 * entregables were extracted FROM, so tier 2 already carries everything it
 * said. See the note in for().
 *
 * ⚠️ THE NUMBERS ARE WHAT HOLD THE ORDER, NOT THE INSERTION. BrandContext::make()
 * ksorts the titles so prompt bytes never depend on map ordering — which means
 * an untitled "Brand Egg…" would have sorted ABOVE "Entregables…" by luck of
 * its initial and below a block called "Archivos…" added later, silently. The
 * Egg taking 1 and shifting the rest down is one cache miss per brand, once.
 *
 * That fourth tier was added on 2026-09-15 and it is a SECOND tier, not a
 * rival. Until then the digest was written by the onboarding assistant and read
 * by nothing at all, so Brandy had never seen a brandbook — only the
 * entregables a person accepted out of one. The ordering, the title and the
 * block's own opening lines all say the same thing: the entregables are what
 * the brand IS; the toolkit is background, and a contradiction is reported
 * rather than resolved.
 *
 * Uploaded documents used to be a fourth. They were dropped on 2026-08-14 and
 * the reason is worth keeping: only .md and .txt were ever read, brandbooks
 * arrive as PDFs, and so the block that promised the model "the material this
 * brand is made of" was empty for every brand that had one. A PDF reaches the
 * entregables the way it always really did — through the onboarding assistant,
 * which reads it as an attachment and proposes content a person accepts.
 */
final class BrandContextRepository
{
    /** Extensions we can read without an external parser. */
    private const TEXT_EXTENSIONS = ['md', 'markdown', 'txt', 'text'];

    public function for(Client $client): BrandContext
    {
        $documents = [];

        // ⚠️ THE BRAND EGG IS TIER ONE — the brand's primary memory, five
        // synthesised layers sitting above the reviewed detail beneath them.
        // See docs/brand-egg.md §7.
        //
        // ⚠️ AN UNAPPROVED EGG IS STILL HERE. The brief says the Egg becomes
        // primary "una vez aprobado", which reads as a gate. It is not
        // implemented as one: every brand is unapproved on the day this ships,
        // and gating would leave all of them with an assistant that knows less
        // than it did the week before. Approval changes what the block SAYS
        // ABOUT the content — see eggBlock() — not whether it is there.
        $egg = $client->brandEggOrNew();

        if (! $egg->isEmpty()) {
            $documents['1. Brand Egg de la marca (memoria principal)'] =
                $this->eggBlock($client, $egg);
        }

        // The entregables are the reviewed detail beneath the Egg. The only
        // source a person reviewed one by one, and the only one that states
        // what is NOT defined — silence is what the model completes with
        // whatever is likeliest.
        $deliverables = $client->deliverablesOrNew();

        if ($deliverables->filledCount() > 0) {
            $documents['2. Entregables de la marca (entregables)'] = $deliverables->toMarkdown();
        }

        // Both of these ride along with real brand content rather than
        // counting as some. Added unconditionally they would make
        // hasUsableContext() true for every brand, including one nobody has
        // written a word about — and that method exists precisely to stop the
        // assistant being offered with nothing behind it.
        if ($documents !== []) {
            $documents['3. La marca (ficha)'] = $this->brandBlock($client);
            $documents['4. Proceso del proyecto (proceso)'] = $this->processBlock($client);
        }

        /*
         * ⚠️ THERE IS NO TOOLKIT TIER, AND THAT IS THE POINT — removed
         * 2026-09-17, having been added on 2026-09-15.
         *
         * The toolkit is the final PDF Breakfast delivers to a brand, and the
         * 48 entregables are EXTRACTED FROM IT. So once that extraction has
         * happened the toolkit has nothing left to say: everything in it is
         * already in tier 2, reviewed one entregable at a time by a person.
         * Feeding the digest as well put an unreviewed second account of the
         * same facts in front of the model on every single turn, competing
         * with the reviewed one.
         *
         * It was added because the digest held something the entregables did
         * not — "cómo se ve", what the material LOOKED like, which no text
         * column carried. That gap is closed: images are read into
         * brand_assets.visual_reading and reach the Egg through its inventory
         * layer, where the description hangs off a row somebody filed and can
         * be corrected.
         *
         * ⚠️ clients.document_digest STILL EXISTS and must stay. It is the
         * working note of the extraction itself — BrandOnboardingController
         * reads a PDF into it once and then makes four batched calls over that
         * stored text, which is what stops a 94MB toolkit travelling five
         * times and what keeps each call inside this host's limits (§3). It is
         * scaffolding for building the entregables, never a source for
         * answering from.
         */

        return BrandContext::make(
            clientId: $client->id,
            clientName: $client->name,
            documents: $documents,
            version: $this->versionFor($client),
        );
    }

    /**
     * True when there is brand content to answer from. Use this to decide
     * whether to show the assistant at all — an assistant with no brand
     * context is worse than no assistant.
     */
    public function hasUsableContext(Client $client): bool
    {
        return ! $this->for($client)->isEmpty();
    }

    /**
     * The five layers, framed by how far the model may lean on them.
     *
     * ⚠️ THE APPROVAL LINE IS INSIDE THE BLOCK, not in the ficha. "Above" is an
     * ordering a model can lose track of in a long prompt, and the ficha is two
     * tiers away from the thing it would be describing — so the tier says what
     * it is in its own first lines, where it cannot be separated from the text
     * it governs. The toolkit tier used to make the same argument in its own
     * first lines, before it was removed for saying what tier 2 already said.
     *
     * ⚠️ THE DATE IS ABSOLUTE AND THE LINE IS STABLE PER BRAND. It lands in
     * block 2, whose exact bytes are the caching mechanism: "aprobado hace dos
     * semanas" would change every fortnight and drop the hit rate to zero
     * silently, at roughly 150x the cost (CLAUDE.md §7).
     */
    private function eggBlock(Client $client, BrandEgg $egg): string
    {
        $state = $client->brandEggState();

        $standing = match ($state) {
            // Written or composed, nobody has signed it off. Still the brand's
            // memory — see for() — but the model is told how far to lean.
            BrandEggState::SinAprobar => 'Todavía SIN APROBAR por Breakfast: es un borrador de '
                .'trabajo. Puedes apoyarte en él, pero no lo presentes como definitivo.',
            BrandEggState::Aprobado => 'APROBADO por Breakfast el '
                .$egg->approved_at->translatedFormat('j \d\e F \d\e Y')
                .'. Es la definición vigente de la marca.',
            // ⚠️ NOT PHRASED AS A FAULT. The entregables moving is the app
            // working; the Egg being behind is the consequence, not somebody's
            // oversight. Same register as ERR-07 of the beta review.
            BrandEggState::Desactualizado => 'Aprobado por Breakfast el '
                .$egg->approved_at->translatedFormat('j \d\e F \d\e Y')
                .', y después se editaron entregables. Donde el Brand Egg y un '
                .'entregable no coincidan, manda el entregable, y lo señalas.',
            // Unreachable: for() only builds this block for a non-empty Egg,
            // and a non-empty Egg is never SinGenerar. Stated rather than left
            // to a default branch, so the day a sixth state appears this is a
            // match error and not a silently empty line.
            BrandEggState::SinGenerar => 'Sin generar.',
        };

        return 'Ésta es la memoria principal de la marca: cinco capas '
            .'sintetizadas a partir de los entregables que Breakfast ya revisó.

'
            .'- Es lo primero que se lee de esta marca. Léelo antes que nada '
            .'más.
'
            .'- No lo cites como si fuera un documento: es cómo se cuenta la '
            .'marca a sí misma.
'
            .'- Los entregables de abajo son el detalle que lo sostiene. Si el '
            .'Brand Egg y un entregable se contradicen, lo señalas y no decides '
            .'por tu cuenta.

'
            .$standing
            .'

---

'
            .$egg->toMarkdown();
    }

    /**
     * The brand's own details, as opposed to its entregables.
     *
     * ⚠️ Absolute values only, like everything else in block 2. "Cliente desde
     * hace cinco meses" would change every month and take the cache with it.
     */
    private function brandBlock(Client $client): string
    {
        $lines = [
            "Nombre: {$client->name}",
            'Industria: '.($client->industry ?: 'NO DEFINIDA'),
            'Marca registrada: '.$client->trademarkLabel(),
            'Estado de la cuenta: '.$client->status->label(),
        ];

        if ($client->onboarded_at !== null) {
            $lines[] = 'Cliente de Breakfast desde el '
                .$client->onboarded_at->translatedFormat('j \d\e F \d\e Y');
        }

        return implode('
', $lines);
    }

    /**
     * Where the project is, for the questions the entregables cannot answer:
     * "¿cuánto falta?", "¿cuándo es la próxima reunión?", "¿más o menos cuándo
     * terminamos?".
     *
     * ⚠️ ABSOLUTE DATES ONLY, and never now(). This lands in block 2, whose
     * exact bytes are the caching mechanism — "faltan tres días" would change
     * every day and "hace dos semanas" would change every fortnight, dropping
     * the hit rate to zero silently. Today's date is stapled to the user turn
     * instead; see BrandContextBuilder::today(). The model does the arithmetic
     * from these fixed points.
     */
    private function processBlock(Client $client): string
    {
        $lines = [];
        $current = $client->currentStep();
        $total = count(ProcessStep::cases());

        foreach (ProcessStep::cases() as $step) {
            $record = $client->stepRecord($step);

            $lines[] = match (true) {
                $record?->isComplete() => "- Paso {$step->number()} · {$step->label()}: completo el "
                    .$record->completed_at->translatedFormat('j \d\e F \d\e Y'),
                $record?->isRunning() => "- Paso {$step->number()} · {$step->label()}: EN CURSO desde el "
                    .$record->started_at->translatedFormat('j \d\e F \d\e Y'),
                default => "- Paso {$step->number()} · {$step->label()}: sin empezar",
            };
        }

        $deliverables = $client->deliverablesOrNew();
        $required = count(DeliverableItem::required());
        $done = $required - count($deliverables->missing());
        $optional = $deliverables->filledCount() - $done;

        $parts = [
            $current === null
                ? 'Paso actual: ninguno en curso.'
                : "Paso actual: {$current->number()} de {$total} · {$current->label()}.",
            implode("\n", $lines),
            "Entregables: {$done} de {$required} obligatorios definidos, más {$optional} opcionales.",
        ];

        $next = $client->nextMeeting();

        // Only the next one. A list of every past meeting would grow without
        // limit and push the brand definition out of the context window.
        $parts[] = $next === null
            ? 'Próxima reunión: NO DEFINIDA. No inventes una fecha; di que no hay ninguna agendada.'
            : 'Próxima reunión: '.$next->scheduled_at->translatedFormat('l j \d\e F \d\e Y, H:i')
                .' — '.$next->title.($next->agenda ? ". Agenda: {$next->agenda}" : '');

        return implode("\n\n", $parts);
    }

    /**
     * Coarse version marker, for auditing which brand definition produced a
     * given answer. Moves whenever the entregables are saved, which is the
     * only thing that changes what this context says.
     */
    private function versionFor(Client $client): ?string
    {
        // ⚠️ THE LATEST OF THE THREE. This string is printed into block 2 as
        // "Versión del contexto", so it has to move whenever the block does —
        // and since the toolkit, the ficha and now the Brand Egg joined it, the
        // entregables' own timestamp is no longer the whole story. Reading a
        // brandbook changes the prompt, and so does composing a layer; a
        // version that still named the old date would be a line claiming the
        // context had not moved while it plainly had.
        $stamps = array_filter([
            $client->deliverables?->updated_at,
            $client->brandEgg?->updated_at,
            $client->updated_at,
        ]);

        return $stamps === []
            ? null
            : collect($stamps)->max()->format('Y-m-d H:i');
    }
}
