<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
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
        ];
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
            'role.in' => 'Ese rol no se puede asignar desde aquí.',
        ];
    }
}
