<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Provider returned 402 — the account is out of credit.
 *
 * Distinct from InvalidRequest because the remediation is operational (top up
 * the DeepSeek account), not a code fix. Worth alerting on separately: it
 * takes down every generation for every client at once.
 */
final class InsufficientBalance extends LlmException
{
    public function __construct(string $message = 'The AI provider account has insufficient balance.')
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return 'El asistente no está disponible temporalmente. El equipo de Breakfast ya fue notificado.';
    }
}
