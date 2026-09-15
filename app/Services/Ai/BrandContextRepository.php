<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\DeliverableItem;
use App\Enums\ProcessStep;
use App\Models\Client;
use App\Services\Ai\Data\BrandContext;

/**
 * Turns a Client into the BrandContext its assistant answers from.
 *
 * This is the ONLY class in the AI layer that touches Eloquent. Everything
 * downstream takes the DTO, so schema changes land here and nowhere else.
 *
 * ⚠️ THE CONTEXT IS THE DATABASE FIRST. Three blocks written by a person —
 * the 48 entregables, the brand's own details, and where the process stands —
 * and, below them, one that was not: what was read out of the brand's uploaded
 * documents (clients.document_digest).
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

        // The entregables are the brand. The only source a person reviewed one
        // by one, and the only one that states what is NOT defined — silence
        // is what the model completes with whatever is likeliest.
        $deliverables = $client->deliverablesOrNew();

        if ($deliverables->filledCount() > 0) {
            $documents['1. Entregables de la marca (entregables)'] = $deliverables->toMarkdown();
        }

        // Both of these ride along with real brand content rather than
        // counting as some. Added unconditionally they would make
        // hasUsableContext() true for every brand, including one nobody has
        // written a word about — and that method exists precisely to stop the
        // assistant being offered with nothing behind it.
        if ($documents !== []) {
            $documents['2. La marca (ficha)'] = $this->brandBlock($client);
            $documents['3. Proceso del proyecto (proceso)'] = $this->processBlock($client);

            // ⚠️ THE TOOLKIT, AND IT IS NEW HERE. clients.document_digest was
            // written by the onboarding assistant and read by NOTHING: Brandy
            // had never seen a brandbook, only the entregables a person
            // accepted from one. The brief worried the toolkit was acting as
            // the brand's main memory; the truth was the inverse.
            //
            // It rides inside this guard for the same reason the two above do.
            // A brand with an uploaded toolkit and not one entregable written
            // would otherwise report hasUsableContext() true, and the assistant
            // would be offered on the strength of a document nobody reviewed —
            // which is the one thing this whole app exists to prevent.
            $toolkit = trim((string) $client->document_digest);

            if ($toolkit !== '') {
                $documents['4. Toolkit de la marca (respaldo, NO es la fuente principal)'] =
                    $this->toolkitBlock($toolkit);
            }
        }

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
     * What was read out of the brand's own documents, framed as backup.
     *
     * ⚠️ THE FRAMING IS PART OF THE DOCUMENT, not only of the house prompt.
     * This block sits below the entregables in the assembled context, but
     * "below" is an ordering a model can lose track of in a long prompt — so
     * the tier says what it is in its own first line, where it cannot be
     * separated from the text it governs.
     *
     * The rule it states is the same one the entregables carry: what the brand
     * IS comes from what a person reviewed. A brandbook is what the material
     * said before anybody agreed to it, which makes it good for detail the
     * entregables do not carry and useless as a way to contradict them.
     */
    private function toolkitBlock(string $digest): string
    {
        return 'Esto es lo que se leyó de los documentos que subió el equipo '
            ."(brandbooks, briefs, presentaciones). Es material de RESPALDO.\n\n"
            .'- Los entregables mandan. Si algo de aquí los contradice, lo '
            ."señalas y no decides por tu cuenta.\n"
            .'- Sirve para dar detalle que los entregables no traen, nunca '
            ."para reemplazarlos.\n"
            .'- Nada de aquí está aprobado: es lo que decían los documentos, '
            ."no lo que la marca definió.\n\n---\n\n".$digest;
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
        // ⚠️ THE LATER OF THE TWO. This string is printed into block 2 as
        // "Versión del contexto", so it has to move whenever the block does —
        // and since the toolkit and the ficha joined it, the entregables' own
        // timestamp is no longer the whole story. Reading a brandbook changes
        // the prompt; a version that still named the old date would be a line
        // claiming the context had not moved while it plainly had.
        $stamps = array_filter([
            $client->deliverables?->updated_at,
            $client->updated_at,
        ]);

        return $stamps === []
            ? null
            : collect($stamps)->max()->format('Y-m-d H:i');
    }
}
