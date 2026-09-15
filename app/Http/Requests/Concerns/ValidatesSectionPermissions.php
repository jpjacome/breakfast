<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccessLevel;
use App\Enums\PortalSection;
use App\Models\User;

/**
 * Shared by every form that hands out portal access: Breakfast staff granting
 * a brand owner in /admin, and that owner granting their team in
 * /portal/equipo. Both post the same checkbox grid, so both must read it the
 * same way.
 *
 * The grid posts one array per section:
 *
 *   permissions[estrategia][] = read
 *   permissions[estrategia][] = write
 */
trait ValidatesSectionPermissions
{
    /** Rules to merge into the form request's own. */
    protected function permissionRules(): array
    {
        return [
            'permissions' => ['nullable', 'array'],
            // Section keys are not validated here on purpose — permissionsPayload()
            // below builds the result by walking the grantor's own sections and
            // reading from the input, so a crafted key is never looked at rather
            // than being rejected with a message that confirms it exists.
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string', 'in:'.implode(',', array_column(AccessLevel::cases(), 'value'))],
        ];
    }

    /**
     * The grid, reduced to one level per section and capped at what the
     * grantor themselves holds.
     *
     * This is the whole "same or less" rule, and it lives here rather than in a
     * validation message on purpose: a read-only owner ticking Editar is not
     * making an error worth an error page, they are asking for something the
     * system quietly gives the most it can of. What they cannot reach at all is
     * dropped rather than clamped, so nothing is granted by accident.
     *
     * @return array<string, string>
     */
    protected function permissionsPayload(User $grantor): array
    {
        $submitted = (array) $this->input('permissions', []);
        $payload = [];

        foreach (PortalSection::grantable() as $section) {
            $ceiling = $grantor->grantCeiling($section);

            // The grantor does not have this section, so it is not theirs to
            // pass on — whatever was posted for it.
            if ($ceiling === null) {
                continue;
            }

            $checked = array_map('strval', (array) ($submitted[$section->value] ?? []));
            $wanted = AccessLevel::fromChecked($checked);

            if ($wanted === null) {
                continue;
            }

            $payload[$section->value] = $ceiling->covers($wanted)
                ? $wanted->value
                : $ceiling->value;
        }

        return $payload;
    }
}
