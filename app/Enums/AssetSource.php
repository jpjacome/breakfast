<?php

namespace App\Enums;

/**
 * How a file got into a brand's folder.
 *
 * ⚠️ NOT A SECOND KIND OF FILE. There were two once — brand assets and
 * "context documents", material uploaded for the assistant and kept on its own
 * screen — and that split was removed on 2026-08-14 because it hid files from
 * the people who needed them (CLAUDE.md §11). This is deliberately NOT that: it
 * is provenance on one row in one list, the same shape as AssetVisibility.
 *
 * What it changes is where the bytes sit and what the row says about itself.
 * What it does NOT change is who may see the file — that is AssetVisibility's
 * question, and it stays separate precisely so a file's origin can never
 * silently decide its audience.
 */
enum AssetSource: string
{
    /** Somebody chose this file on a form and uploaded it to the brand. */
    case Subida = 'subida';

    /**
     * Somebody attached it to a turn with Brandy and it was kept.
     *
     * The team uploads a brandbook to have it read; before this, the bytes went
     * to the provider and were dropped, so getting that same file into the
     * brand's folder meant uploading it a second time.
     */
    case Referencia = 'referencia';

    public function label(): string
    {
        return match ($this) {
            self::Subida => 'Subido',
            self::Referencia => 'Referencia',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Subida => 'Subido a mano a la carpeta de la marca.',
            self::Referencia => 'Llegó como adjunto en una conversación con Brandy.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Subida => 'admin-badge',
            self::Referencia => 'admin-badge admin-badge-quiet',
        };
    }

    /**
     * The folder inside the brand's directory.
     *
     * Kept apart so a brandbook read in passing does not sit among the pieces
     * Breakfast deliberately handed the brand — the folder stays legible while
     * the list stays single.
     */
    public function folder(): string
    {
        return match ($this) {
            self::Subida => 'assets',
            self::Referencia => 'referencias',
        };
    }
}
