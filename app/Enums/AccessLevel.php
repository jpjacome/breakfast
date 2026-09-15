<?php

namespace App\Enums;

/**
 * How much of a portal section someone may use.
 *
 * Stored as the value in User::$permissions, keyed by PortalSection. There is
 * deliberately no "none" case: a section simply absent from that array means
 * no access, so "cannot enter" has exactly one representation rather than two
 * that have to be kept in sync.
 */
enum AccessLevel: string
{
    /** Can open the section and read it. */
    case Read = 'read';

    /** Can open it and change what is in it. Implies Read. */
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Ver',
            self::Write => 'Ver y editar',
        };
    }

    /** True when holding this level satisfies a requirement for $needed. */
    public function covers(self $needed): bool
    {
        return $this === self::Write || $needed === self::Read;
    }

    /**
     * Highest level in a set of checkbox values, or null for none ticked.
     *
     * The grid posts one box per section now that grantable sections are
     * read-only throughout — see User::grantCeiling() — so in practice this
     * reads a single "read". "write" is still understood because the payload
     * is hand-editable, and it is clamped to the ceiling on the way in rather
     * than rejected; editing without seeing is not a state that means
     * anything, so ticking only "write" is read as both.
     */
    public static function fromChecked(array $checked): ?self
    {
        if (in_array(self::Write->value, $checked, true)) {
            return self::Write;
        }

        if (in_array(self::Read->value, $checked, true)) {
            return self::Read;
        }

        return null;
    }
}
