<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesBrandAssignments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding someone to Breakfast. Admin-only: a staff account reaches brands
 * nobody outside the company should see, so handing one out is not a
 * day-to-day action.
 */
class StoreStaffMemberRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
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
            'email' => 'correo',
            'role' => 'rol',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'role.in' => 'Ese rol no es del equipo Breakfast.',
            ...$this->brandAssignmentMessages(),
        ];
    }
}
