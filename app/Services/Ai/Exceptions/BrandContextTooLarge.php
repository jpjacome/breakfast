<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * A client's context documents exceed the configured character ceiling.
 *
 * Deliberately fatal rather than truncating: a silently trimmed brand
 * definition produces confidently off-brand answers, which is worse than a
 * visible error an admin can act on.
 */
final class BrandContextTooLarge extends LlmException
{
    public function userMessage(): string
    {
        return 'El contexto de marca de este cliente es demasiado extenso. '
            .'Contacta al equipo de Breakfast para depurarlo.';
    }
}
