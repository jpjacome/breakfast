<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\InsufficientBalance;
use App\Services\Ai\Exceptions\InvalidRequest;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Exceptions\ProviderUnavailable;
use App\Services\Ai\Exceptions\RateLimited;

/**
 * What a person is told when a turn does not come back.
 *
 * THE ONE PLACE THAT DECIDES IT, for both sides. Until the beta review the
 * controllers answered with $e->getMessage() verbatim, which is written for
 * whoever reads laravel.log: a client asking about their campaign was shown
 * "DeepSeek 500: upstream error", naming a supplier they have no relationship
 * with and a status code they cannot act on. The report asked for it to stop,
 * and it was right to.
 *
 * TWO AUDIENCES, ONE DECISION. Breakfast staff keep the technical sentence —
 * they are the ones who forward the screenshot, and stripping the detail there
 * would cost the afternoon that CLAUDE.md §3 already describes losing once. A
 * client gets a sentence about what to do next and nothing about who failed.
 * Both come from here so the two cannot drift.
 *
 * ⚠️ THE REAL MESSAGE IS NEVER LOST. Callers report($e) before asking for this,
 * so what the provider actually said is in laravel.log either way. This decides
 * what reaches a screen, not what is recorded.
 */
final class AssistantFailure
{
    /**
     * The sentence to show, given who is looking.
     *
     * $forStaff is a Breakfast user's own panel — /admin, or the brandbook
     * reader beside the entregables board.
     */
    public static function message(LlmException $e, bool $forStaff = false): string
    {
        if ($forStaff) {
            return $e->getMessage();
        }

        return match (true) {
            // Both read the same way from the outside: something upstream is
            // busy or down, and the useful advice is identical.
            $e instanceof ProviderUnavailable,
            $e instanceof RateLimited => 'Se me cruzaron los cables un segundo. Vuelve a '
                .'intentarlo — tu pregunta sigue escrita ahí abajo.',

            // The account is empty or the key is wrong. Nothing the person
            // reading this can do, and telling them the truth would be telling
            // them about Breakfast's billing, so it becomes a human handoff.
            $e instanceof InsufficientBalance,
            $e instanceof InvalidRequest => 'Ahora mismo no puedo responder. Si sigue '
                .'pasando, escríbele al equipo de Breakfast a info@vamosdebreakfast.com.',

            default => 'No pude responder esta vez. Inténtalo de nuevo en un momento.',
        };
    }
}
