<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * One window of the ai_usage_logs ledger, totalled.
 *
 * Costs stay in integer micro-USD (1e-6 USD) all the way to the view, the same
 * unit the ledger stores, because a question costs well under a cent and
 * rounding to cents anywhere in the middle turns real spend into a column of
 * zeroes. They become a float exactly once, in usd().
 */
final readonly class UsageSummary
{
    public function __construct(
        public int $requests = 0,
        public int $failures = 0,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cacheHitTokens = 0,
        public int $costMicroUsd = 0,
        /** Rows in this window whose cost the provider reported rather than us estimating it. */
        public int $reportedRows = 0,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function usd(): float
    {
        return $this->costMicroUsd / 1_000_000;
    }

    public function formattedUsd(): string
    {
        return self::formatMicroUsd($this->costMicroUsd);
    }

    /**
     * Micro-USD as money, for this summary and for the per-model and per-brand
     * rows that are plain ledger totals rather than a summary.
     *
     * A question costs fractions of a cent, so two decimals would show $0.00
     * for a real day's work. Small amounts get four; once a total is worth
     * reading as money, two is what you want.
     */
    public static function formatMicroUsd(int $microUsd): string
    {
        $usd = $microUsd / 1_000_000;

        return '$'.number_format($usd, $usd >= 1 ? 2 : 4);
    }

    /** Share of prompt tokens served from cache, 0.0–1.0. */
    public function cacheHitRate(): float
    {
        return $this->promptTokens > 0
            ? $this->cacheHitTokens / $this->promptTokens
            : 0.0;
    }

    /**
     * True when every row in the window carries the provider's own cost, so
     * the total is what was actually charged rather than a price-table guess.
     * Mixed windows (a provider switch mid-month) count as not exact.
     */
    public function costIsExact(): bool
    {
        return $this->requests > 0 && $this->reportedRows === $this->requests;
    }

    public function successRate(): float
    {
        $attempts = $this->requests + $this->failures;

        return $attempts > 0 ? $this->requests / $attempts : 1.0;
    }
}
