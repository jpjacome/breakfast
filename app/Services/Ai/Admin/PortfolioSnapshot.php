<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The state of every brand, as a compact table the model can read.
 *
 * This is what the admin assistant runs on. It is NOT the brands' documents:
 * dumping twelve brandbooks into a prompt would be ruinous and would answer
 * none of the questions this assistant exists for, which are about the state
 * of the business rather than the content of any one brand.
 *
 * With a dozen brands this is roughly two thousand tokens and answers most of
 * what gets asked. A question that narrows to one brand gets that brand's
 * ficha appended — see AdminContextBuilder.
 *
 * ⚠️ ABSOLUTE DATES ONLY, and never a relative one. This lands in the cacheable
 * part of the prompt, so "hace tres días" would change daily and drop the hit
 * rate to zero. Today's date rides on the user turn instead, and the model does
 * the arithmetic.
 *
 * ⚠️ EVERY FIGURE HERE IS COMPUTED FROM THE DATABASE. The whole risk of this
 * assistant is inventing numbers and dates, so the rule the prompt enforces is
 * that any figure in an answer must appear literally in this block. That only
 * works if the block is exhaustive about the figures it offers.
 */
final class PortfolioSnapshot
{
    /**
     * The whole portfolio, scoped to what this user may see.
     *
     * Scoped, not global: an Equipo member put on two brands must not learn the
     * roster by asking the assistant what they cannot see in the list. The
     * scope is Client::visibleTo(), the same one the screens use.
     */
    public function forUser(User $user): string
    {
        $clients = Client::query()
            ->visibleTo($user)
            ->with(['deliverables', 'processSteps', 'meetings'])
            ->withCount('brandAssets')
            ->orderBy('name')
            ->get();

        if ($clients->isEmpty()) {
            return 'No hay ninguna marca asignada a esta cuenta.';
        }

        $rows = $clients->map(fn (Client $client) => $this->row($client));

        return "Marcas: {$clients->count()}.\n\n".$rows->implode("\n\n");
    }

    /**
     * One brand in depth, appended when the question is about that brand.
     *
     * ⚠️ THIS CARRIES THE 48 ENTREGABLES THEMSELVES, not just how many are
     * filled. Without them the assistant could say Alea had 18 of 48 and still
     * not answer "¿ya tiene colores definidos?", which is the question people
     * actually ask — the count is a progress bar, and what they want to know is
     * what is IN the row. A tester hit exactly that and was told, wrongly, that
     * the data was not available.
     *
     * toMarkdown() rather than a list of labels, and the same method the client
     * assistant reads, so both sides describe a brand identically: filled ones
     * carry their text, empty required ones say NO DEFINIDO, and empty optional
     * ones are named in one closing line. Absence is stated, never omitted —
     * silence is what the model completes with something plausible.
     *
     * Only ever ONE brand's worth. The portfolio table stays a table; this is
     * the appendix, and appending it for every brand at once is how a prompt
     * gets ruinous.
     */
    public function ficha(Client $client): string
    {
        $client->loadMissing(['deliverables', 'processSteps', 'meetings', 'users']);

        $deliverables = $client->deliverablesOrNew();
        $missing = $deliverables->missing();

        $lines = [
            "# {$client->name}",
            'Estado: '.$client->status->label(),
            'Industria: '.($client->industry ?: 'NO DEFINIDA'),
            'Marca registrada: '.$client->trademarkLabel(),
            'Alta: '.($client->onboarded_at?->translatedFormat('j \d\e F \d\e Y')
                ?? 'todavía es borrador, sin dar de alta'),
            'Personas de la marca con acceso: '.$client->users->count(),
            'Archivos entregados: '.$client->brandAssets()->count(),
        ];

        if ($missing !== []) {
            $lines[] = 'Entregables obligatorios que faltan: '
                .implode(', ', array_map(fn (DeliverableItem $i) => $i->label(), $missing));
        }

        $past = $client->meetings->filter(fn ($m) => ! $m->isUpcoming() && ! $m->isCancelled());

        if ($past->isNotEmpty()) {
            $last = $past->sortByDesc('scheduled_at')->first();
            $lines[] = 'Última reunión: '.$last->scheduled_at->translatedFormat('j \d\e F \d\e Y')
                .' — '.$last->title
                .($last->notes ? ". Notas: {$last->notes}" : '. Sin notas.');
        }

        $lines[] = '';
        $lines[] = '## Los 48 entregables de esta marca, tal como están escritos';
        $lines[] = '';
        $lines[] = $deliverables->toMarkdown();

        return implode("\n", $lines);
    }

    /**
     * One brand's line in the table.
     *
     * ⚠️ No spend figure here. It lives in block 3 with the clock — see
     * SpendDigest — because answering a question writes a ledger row, so a
     * total printed in this block would change the cacheable prefix on every
     * turn. What is left here changes when somebody does work on a brand.
     */
    private function row(Client $client): string
    {
        $deliverables = $client->deliverablesOrNew();
        $required = count(DeliverableItem::required());
        $done = $required - count($deliverables->missing());
        $filled = $deliverables->filledCount();
        $step = $client->currentStep();
        $next = $client->nextMeeting();
        $activity = $this->lastActivity($client);

        return implode("\n", [
            "## {$client->name} ({$client->slug})",
            'Estado: '.$client->status->label(),
            // The date the brand became a brand, so "¿cuál fue la última marca
            // que dimos de alta?" can be answered by sorting rather than
            // guessed. onboarded_at is stamped when the draft is finished, not
            // when the half-filled form was opened — see StartBrandDraft.
            'Alta: '.($client->onboarded_at?->translatedFormat('j \d\e F \d\e Y')
                ?? 'todavía es borrador, sin dar de alta'),
            'Paso: '.($step
                ? "{$step->number()} de 3 · {$step->label()}"
                : ($client->completedStepCount() === 3 ? 'los 3 completos' : 'sin empezar')),
            // What is DONE and what is LEFT, both spelled out. "¿Cuánto le
            // falta?" is the commonest question asked of this table, and the
            // rule is that a figure must appear literally — so the subtraction
            // is done here rather than left for the model to do in its head.
            "Entregables completados: {$filled} de 48 (faltan ".(48 - $filled).')',
            "Entregables obligatorios: {$done} de {$required} (faltan ".($required - $done).')',
            'Archivos entregados: '.$client->brand_assets_count,
            'Próxima reunión: '.($next
                ? $next->scheduled_at->translatedFormat('j \d\e F \d\e Y, H:i').' — '.$next->title
                : 'ninguna agendada'),
            'Última actividad: '.($activity?->translatedFormat('j \d\e F \d\e Y') ?? 'ninguna registrada'),
        ]);
    }

    /**
     * When anything last happened on this brand.
     *
     * "Trabada" and "sin actividad" are the questions this assistant is built
     * for, and neither can be answered from the client row alone — a brand
     * whose row has not changed since it was created may have had a meeting
     * yesterday. So it is the latest of everything that counts as work.
     */
    private function lastActivity(Client $client): ?Carbon
    {
        $dates = collect([
            $client->deliverables?->updated_at,
            $client->processSteps->max('completed_at'),
            $client->processSteps->max('started_at'),
            $client->meetings->max('scheduled_at'),
            $client->brandAssets()->max('created_at'),
        ])->filter();

        if ($dates->isEmpty()) {
            return $client->created_at;
        }

        return $dates->map(fn ($d) => Carbon::parse($d))->max();
    }
}
