<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesSectionPermissions;
use App\Models\User;
use Closure;
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
            //
            // ⚠️ … unless it is a BREAKFAST address. The role picker below only
            // offers the two client roles, which reads as enough and is not:
            // that role applies to an account being CREATED, and an address
            // that already exists is attached with whatever role it already
            // has. So typing a staff member's own address here put Breakfast
            // staff in brand_user, past a rule that looked like it covered
            // this. Said as a field error rather than left to the exception in
            // InviteUserToClient::attach(), which is the backstop.
            'email' => ['required', 'email', 'max:190', $this->notBreakfastStaff()],
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

    /**
     * Refuse an address that belongs to someone who works at Breakfast.
     *
     * A closure rather than a Rule class: it is one question asked in one
     * place, and the reason it exists is written above where it is used.
     */
    private function notBreakfastStaff(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $existing = User::where('email', $value)->first();

            if ($existing !== null && $existing->isBreakfast()) {
                $fail('Esa dirección es de una cuenta del equipo de Breakfast. '
                    .'El equipo entra a todas las marcas por su rol, no hace falta agregarlo aquí.');
            }
        };
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
