<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * The brand checklist on the staff form, shared by the create and edit
 * requests because a posted id has to survive the same checks either way.
 */
trait ValidatesBrandAssignments
{
    /** @return array<string, mixed> */
    protected function brandAssignmentRules(): array
    {
        return [
            // Absent means "none" — an unticked checkbox group posts nothing
            // at all, and that is a real answer, not a missing field.
            'clients' => ['sometimes', 'array'],
            'clients.*' => ['integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * The brand ids to sync, de-duplicated.
     *
     * Read from validated() rather than input() so a slug or an id for a
     * soft-deleted brand cannot reach ->sync() by being posted by hand.
     *
     * @return array<int, int>
     */
    public function clientIds(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            $this->validated('clients') ?? [],
        )));
    }

    /** @return array<string, string> */
    protected function brandAssignmentMessages(): array
    {
        return [
            'clients.*.exists' => 'Una de las marcas seleccionadas ya no existe.',
        ];
    }
}
