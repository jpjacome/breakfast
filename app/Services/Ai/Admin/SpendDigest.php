<?php

declare(strict_types=1);

namespace App\Services\Ai\Admin;

use App\Models\User;
use App\Services\Ai\Data\UsageSummary;
use App\Services\Ai\UsageStatistics;

/**
 * What Breakfast is spending on the AI, as a few literal lines.
 *
 * Reads UsageStatistics — the same class the /admin dashboard reads — rather
 * than querying ai_usage_logs again, so the assistant and the dashboard can
 * never disagree about a number the team is looking at on two screens.
 *
 * ⚠️ THIS BELONGS IN BLOCK 3, WITH TODAY'S DATE, AND NOT IN THE SNAPSHOT.
 *
 * Spend is a live counter: answering an admin question writes a row to the
 * ledger, so the total is different by the time the next question is asked. In
 * the cacheable block that would change the prefix on every single turn and
 * drop the cache hit rate to zero — silently, the way trap 4 describes, with
 * costs up ~150x and nothing in the response saying so. The irony of the
 * *spending* report being the thing that made every request 150x dearer is
 * why this class exists separately instead of two more lines in
 * PortfolioSnapshot.
 *
 * The per-brand line moved here from the snapshot for the same reason. What is
 * left in block 2 is the state of the brands, which changes when somebody does
 * work; what is in block 3 is the clock and the money, which change by
 * themselves.
 */
final class SpendDigest
{
    /** The window the dashboard uses, so both screens say the same thing. */
    private const DAYS = 30;

    public function __construct(private readonly UsageStatistics $usage) {}

    /**
     * ⚠️ Every figure the model may quote has to appear here literally — it is
     * forbidden from doing arithmetic of its own, so anything worth asking is
     * worth printing. That includes the totals somebody would otherwise expect
     * it to add up from the per-brand lines.
     */
    public function forUser(User $user): string
    {
        $today = $this->usage->summary(1);
        $month = $this->usage->summary(self::DAYS);

        $lines = [
            '# Gasto de IA',
            '',
            'Todo en dólares. El registro cobra por petición: cuando el proveedor',
            'informa el costo real se usa ése, y si no, la tabla de precios.',
            '',
            'Hoy: '.$this->money($today).' en '.$today->requests.' peticiones.',
            'Últimos '.self::DAYS.' días: '.$this->money($month).' en '.$month->requests.' peticiones.',
        ];

        if ($month->failures > 0) {
            // Failed calls carry no cost. Said out loud so a gap between the
            // request count here and one on the dashboard is not read as money.
            $lines[] = 'Peticiones fallidas en el periodo: '.$month->failures.' (no cuestan nada).';
        }

        $lines[] = '';
        $lines[] = 'Por marca, últimos '.self::DAYS.' días:';

        // limit: 100 rather than the dashboard's 5 — a top-five list is a
        // dashboard widget, but a question like "¿cuál es la marca más cara?"
        // has to be able to see every brand or the answer is wrong and looks
        // right. Scoped to the brands this user may work on.
        $brands = $this->usage->byClient(self::DAYS, limit: 100, user: $user);

        if ($brands->isEmpty()) {
            $lines[] = '- Todavía no hay gasto registrado en este periodo.';

            return implode("\n", $lines);
        }

        foreach ($brands as $row) {
            $lines[] = '- '.($row->client_name
                // client_id is null for questions asked across the whole
                // roster from the dashboard: Breakfast's own overhead rather
                // than any one brand's, and it must not be read as a brand.
                ?? 'Sin marca (preguntas generales del panel, borradores)')
                .': '.UsageSummary::formatMicroUsd((int) $row->cost_micro_usd)
                .' en '.$row->requests.' peticiones.';
        }

        return implode("\n", $lines);
    }

    private function money(UsageSummary $summary): string
    {
        return $summary->formattedUsd();
    }
}
