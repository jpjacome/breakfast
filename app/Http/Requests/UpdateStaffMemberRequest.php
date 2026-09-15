<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesBrandAssignments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a teammate's name, rol or brands. Admin-only, same reasoning as
 * StoreStaffMemberRequest — an edit that flips someone to Admin, or adds a
 * brand to their list, hands out the same thing an invitation does.
 *
 * The address is not on the list. Nobody changes an email in this app, their
 * own or anyone else's: a wrong one is removed and re-invited. Leaving the
 * rule out of the rules is what makes it true — a field validated here is a
 * field StaffController would be free to write.
 */
class UpdateStaffMemberRequest extends FormRequest
{
    use ValidatesBrandAssignments;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'role' => [
                'required',
                Rule::in(array_column(UserRole::breakfastRoles(), 'value')),
            ],
            ...$this->brandAssignmentRules(),
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->validated('role'));
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'role' => 'rol',
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Ese rol no es del equipo Breakfast.',
            ...$this->brandAssignmentMessages(),
        ];
    }
}
