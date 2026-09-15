<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesSectionPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientUserRequest extends FormRequest
{
    use ValidatesSectionPermissions;

    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // ⚠️ NOT unique. An address that already has an account is added
            // to this brand rather than refused — one account, many brands
            // (ACC-01). InviteUserToClient decides which of the two happened.
            'email' => ['required', 'email', 'max:190'],
            'role' => [
                'required',
                new Enum(UserRole::class),
                // Only the two client-side roles may be assigned here.
                // Creating Breakfast staff is deliberately not a web action.
                Rule::in(array_map(
                    fn (UserRole $r) => $r->value,
                    UserRole::clientRoles(),
                )),
            ],
            ...$this->permissionRules(),
        ];
    }

    /** @return array<string, string> */
    public function permissions(): array
    {
        // Breakfast staff hold Write everywhere, so nothing is capped here.
        return $this->permissionsPayload($this->user());
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
            'role.in' => 'Ese rol no se puede asignar desde aquí.',
        ];
    }
}
