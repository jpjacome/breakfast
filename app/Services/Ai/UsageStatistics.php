<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Data\UsageSummary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the ai_usage_logs ledger for the admin dashboard.
 *
 * Query builder rather than a model, matching UsageRecorder on the write side:
 * the table shape is the contract and neither half owns an Eloquent class.
 *
 * Every method takes a window in days so the dashboard can ask for "hoy" and
 * "30 días" from the same code. Failed rows are counted but never summed —
 * they carry no tokens and no cost, and folding them into a cache hit rate
 * would drag it toward zero for reasons that have nothing to do with caching.
 *
 * The aggregates are written with CASE rather than the tidier FILTER clause,
 * and test truthiness rather than comparing to 1, because the same SQL has to
 * run on SQLite here and MySQL on the host — where a boolean is a tinyint.
 */
final class UsageStatistics
{
    public function summary(int $days = 30): UsageSummary
    {
        $row = $this->window($days)->selectRaw(<<<'SQL'
            SUM(CASE WHEN succeeded THEN 1 ELSE 0 END)        AS requests,
            SUM(CASE WHEN succeeded THEN 0 ELSE 1 END)        AS failures,
            SUM(prompt_tokens)                                AS prompt_tokens,
            SUM(completion_tokens)                            AS completion_tokens,
            SUM(cache_hit_tokens)                             AS cache_hit_tokens,
            SUM(cost_micro_usd)                               AS cost_micro_usd,
            SUM(CASE WHEN cost_is_reported THEN 1 ELSE 0 END) AS reported_rows
        SQL)->first();

        return new UsageSummary(
            requests: (int) ($row->requests ?? 0),
            failures: (int) ($row->failures ?? 0),
            promptTokens: (int) ($row->prompt_tokens ?? 0),
            completionTokens: (int) ($row->completion_tokens ?? 0),
            cacheHitTokens: (int) ($row->cache_hit_tokens ?? 0),
            costMicroUsd: (int) ($row->cost_micro_usd ?? 0),
            reportedRows: (int) ($row->reported_rows ?? 0),
        );
    }

    /**
     * Spend per model, dearest first — the list that answers "what is actually
     * costing us money", which is rarely the model doing the most requests.
     *
     * @return Collection<int, object>
     */
    public function byModel(int $days = 30): Collection
    {
        return $this->window($days)
            ->where('succeeded', true)
            ->groupBy('model')
            ->selectRaw('model, COUNT(*) AS requests, SUM(prompt_tokens + completion_tokens) AS tokens, SUM(cost_micro_usd) AS cost_micro_usd')
            ->orderByDesc('cost_micro_usd')
            ->get();
    }

    /**
     * Spend per brand, dearest first.
     *
     * client_id is null for questions asked across the whole roster from the
     * dashboard. Those are Breakfast's own cost rather than any one brand's,
     * so they come back with a null name and the view labels them.
     *
     * Pass $user to scope the list to the brands that user may work on. This
     * row names a brand, so an Equipo member not put on it must not see it —
     * unlike the totals above, which name nobody.
     *
     * @return Collection<int, object>
     */
    public function byClient(int $days = 30, int $limit = 5, ?User $user = null): Collection
    {
        return $this->window($days)
            ->where('ai_usage_logs.succeeded', true)
            ->leftJoin('clients', 'clients.id', '=', 'ai_usage_logs.client_id')
            ->when(
                $user !== null && ! $user->coversEveryBrand(),
                // Nested, so the OR stays inside this condition. Left flat it
                // would bind against the succeeded check above and drag every
                // failed roster-wide row back into the results.
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereIn(
                        'ai_usage_logs.client_id',
                        fn ($sub) => $sub->select('client_id')
                            ->from('client_staff')
                            ->where('user_id', $user->getKey()),
                    )
                    // Roster-wide rows keep their place: they are nobody's
                    // brand, so there is nothing to disclose by showing them.
                    ->orWhereNull('ai_usage_logs.client_id')),
            )
            ->groupBy('ai_usage_logs.client_id', 'clients.name')
            ->selectRaw('clients.name AS client_name, COUNT(*) AS requests, SUM(ai_usage_logs.cost_micro_usd) AS cost_micro_usd')
            ->orderByDesc('cost_micro_usd')
            ->limit($limit)
            ->get();
    }

    /**
     * Columns are table-qualified because byClient() joins clients, and both
     * tables have created_at — unqualified, SQLite rejects the query outright.
     */
    private function window(int $days): Builder
    {
        return DB::table('ai_usage_logs')
            ->where('ai_usage_logs.created_at', '>=', now()->subDays($days));
    }
}
