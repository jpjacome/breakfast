<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Carbon\CarbonInterval;

/**
 * The invitation and the "olvidé mi contraseña" mail are different messages
 * carrying the same link, built the same way. This is that link.
 */
trait BuildsPasswordResetLink
{
    /**
     * The absolute URL of /reset-password/{token} for this token.
     *
     * Built with url(route(..., absolute: false)) — the same shape Laravel's
     * own ResetPassword notification uses — so the host comes from APP_URL
     * when there is no request to read it from, which is every send that
     * happens from artisan or a queued job.
     */
    protected function resetUrl(object $notifiable, string $token): string
    {
        return url(route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));
    }

    /**
     * How long the link stays good, worded for a person: "7 días", "1 hora".
     *
     * Not the raw minute count. config/auth.php holds 10080 for a week, and
     * "el enlace vence en 10080 minutos" is a number nobody can read — the
     * whole point of the sentence is that the reader knows whether they have
     * time. Cascading turns it into the largest sensible unit.
     *
     * Spanish is passed explicitly rather than read from the app locale
     * because every other word in these mails is hardcoded Spanish; letting
     * this one string follow a locale switch would strand it mid-sentence.
     */
    protected function linkLifetime(): string
    {
        $minutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60,
        );

        return CarbonInterval::minutes($minutes)->cascade()->forHumans(['locale' => 'es']);
    }
}
