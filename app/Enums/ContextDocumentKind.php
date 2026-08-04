<?php

namespace App\Enums;

/**
 * What a context document IS. Drives how the AI layer will weight it when
 * building brand context — a brandbook outranks a workshop note.
 */
enum ContextDocumentKind: string
{
    case Brief = 'brief';
    case Estrategia = 'estrategia';
    case Brandbook = 'brandbook';
    case Investigacion = 'investigacion';
    case Taller = 'taller';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Brief => 'Brief',
            self::Estrategia => 'Estrategia',
            self::Brandbook => 'Brandbook',
            self::Investigacion => 'Investigación',
            self::Taller => 'Notas de taller',
            self::Otro => 'Otro',
        };
    }

    /** One brand accent per kind, held consistently across the app. */
    public function color(): string
    {
        return match ($this) {
            self::Brief => 'var(--bkf-blue)',
            self::Estrategia => 'var(--bkf-green)',
            self::Brandbook => 'var(--bkf-yellow)',
            self::Investigacion => 'var(--bkf-orange)',
            self::Taller => 'var(--bkf-brown)',
            self::Otro => 'var(--bkf-gray-400)',
        };
    }
}
