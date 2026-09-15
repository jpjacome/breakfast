<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The three moments a meeting is announced before it happens.
 *
 * From SEG-01 of the beta review, and its wording is the specification:
 *
 *   · un aviso el lunes de la semana de la reunión
 *   · 24 horas antes
 *   · 1 hora antes
 *   · si la reunión ocurre un lunes, el primer aviso el viernes anterior
 *
 * Each case knows when it fires and how to say so, the way every other enum in
 * this app carries its own presentation. Nothing outside here calculates a
 * reminder time.
 *
 * ⚠️ TIMES ARE ECUADOR TIME, like everything else in this database. scheduled_at
 * is local (CLAUDE.md §4), so subtracting an hour from it gives a local instant
 * and comparing that against now() is comparing like with like. Do not convert.
 */
enum ReminderWindow: string
{
    /** The Monday that opens the meeting's week — or the Friday before it. */
    case Semana = 'semana';

    /** One day before. */
    case Dia = 'dia';

    /** One hour before. */
    case Hora = 'hora';

    /**
     * When this reminder should go out for a meeting at $scheduledAt.
     *
     * ⚠️ THE MONDAY RULE HAS A CORNER AND THE REPORT NAMED IT. "El lunes de la
     * semana de la reunión" is useless when the meeting IS on Monday: the notice
     * would arrive the same morning, hours before the meeting it is warning
     * about, and after a weekend in which nobody could prepare. So a Monday
     * meeting is announced the Friday before — which is what Breakfast asked
     * for, and what a person would do.
     */
    public function firesAt(Carbon $scheduledAt): CarbonImmutable
    {
        $meeting = CarbonImmutable::instance($scheduledAt);

        return match ($this) {
            // 08:00 rather than midnight: a notice timestamped 00:00 Monday was
            // written on Sunday night as far as anybody reading it is concerned.
            self::Semana => $meeting->isMonday()
                ? $meeting->subDays(3)->setTime(8, 0)
                : $meeting->startOfWeek(Carbon::MONDAY)->setTime(8, 0),
            self::Dia => $meeting->subDay(),
            self::Hora => $meeting->subHour(),
        };
    }

    /** How the notice refers to itself. */
    public function label(): string
    {
        return match ($this) {
            self::Semana => 'Esta semana',
            self::Dia => 'Mañana',
            self::Hora => 'En una hora',
        };
    }

    /**
     * The sentence that opens the notice.
     *
     * Written per window because "tu reunión es en una hora" and "tu reunión es
     * esta semana" are different messages, and one generic line covering both
     * would be a worse version of each.
     */
    public function sentence(): string
    {
        return match ($this) {
            self::Semana => 'Esta semana tienes reunión con Breakfast.',
            self::Dia => 'Mañana tienes reunión con Breakfast.',
            self::Hora => 'Tu reunión con Breakfast es en una hora.',
        };
    }

    /**
     * How stale a missed window may be before it is dropped, in hours.
     *
     * ⚠️ THIS IS WHAT STOPS A LATE CRON EMBARRASSING US. The scheduler on this
     * host may not run for a day (CLAUDE.md §3), and a command that simply sent
     * every window whose time had passed would then announce "tu reunión es en
     * una hora" about a meeting three hours old. A window that missed its moment
     * by more than this is skipped rather than sent late.
     *
     * The tolerance scales with the window because being an hour late on a
     * week's notice costs nothing, while being an hour late on an hour's notice
     * is the entire message being wrong.
     */
    public function graceHours(): int
    {
        return match ($this) {
            self::Semana => 48,
            self::Dia => 12,
            self::Hora => 1,
        };
    }
}
