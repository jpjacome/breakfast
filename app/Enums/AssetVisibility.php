<?php

namespace App\Enums;

/**
 * Who a file in a brand's folder is for.
 *
 * ⚠️ THIS IS NOT THE OLD context_documents SPLIT COMING BACK. That one divided
 * files by what they FED — the client's screen versus the assistant's prompt —
 * and it failed because the two lived in separate lists: people uploaded a logo
 * pack expecting the assistant to read it, and deleted a brandbook believing it
 * was a deliverable. It was folded away on 2026-08-14.
 *
 * This divides by WHO MAY SEE, which is a question a person can actually answer
 * while looking at the file, and it does it inside ONE list with a badge on the
 * row rather than in two lists that hide each other. A contract draft, a
 * pricing sheet and the team's working notes belong in the brand's folder and
 * do not belong in front of the brand.
 *
 * ⚠️ COMPARTIDO IS THE DEFAULT, and deliberately so: it is what every file
 * uploaded before this existed already was, and a migration that quietly hid
 * files a client had been using would be the worse mistake of the two. The
 * dangerous direction — an internal file shown to the client — is the one the
 * admin has to actively not choose, so the upload form asks rather than
 * assumes, and the visibility can be changed after the fact without deleting
 * anything.
 */
enum AssetVisibility: string
{
    /** Breakfast and the brand. What a deliverable is. */
    case Compartido = 'compartido';

    /** Breakfast only. The brand is never told it exists. */
    case Interno = 'interno';

    public function label(): string
    {
        return match ($this) {
            self::Compartido => 'Compartido con la marca',
            self::Interno => 'Sólo para Breakfast',
        };
    }

    /** The short form, for a badge on a row where the column is narrow. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Compartido => 'Compartido',
            self::Interno => 'Interno',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Compartido => 'La marca lo ve en sus archivos y puede descargarlo.',
            self::Interno => 'No aparece en el portal de la marca. Sólo el equipo de Breakfast lo ve.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Compartido => 'users',
            self::Interno => 'lock',
        };
    }

    /**
     * admin.css badge modifier, or '' for the neutral badge.
     *
     * Only the internal one is coloured. Shared is the ordinary case and the
     * overwhelming majority of a folder; badging every row would make the page
     * a wall of colour with nothing standing out, which is the opposite of what
     * this is for. The one worth catching out of the corner of an eye is the
     * file the client must not see.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Compartido => '',
            self::Interno => 'admin-badge-warning',
        };
    }

    public function isInternal(): bool
    {
        return $this === self::Interno;
    }

    /** What an upload gets when nothing said otherwise. */
    public static function default(): self
    {
        return self::Compartido;
    }
}
