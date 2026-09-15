<?php

namespace App\Http\Requests;

use App\Enums\PortalSection;
use App\Http\Requests\Concerns\ValidatesSectionPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A brand owner adding someone to their own team.
 *
 * The invited user is always a member: an owner cannot mint a second owner,
 * because "who pays" is a Breakfast decision, not a client one.
 */
class StoreTeamMemberRequest extends FormRequest
{
    use ValidatesSectionPermissions;

    public function authorize(): bool
    {
        return $this->user()?->canWrite(PortalSection::Equipo) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            ...$this->permissionRules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
        ];
    }

    public function messages(): array
    {
        return [
            // ⚠️ Still refused here, unlike on the Breakfast side. Attaching
            // would tell a brand owner that an account exists on an address
            // they only guessed at, which is somebody else's business.
            'email.unique' => 'Ya existe una cuenta con ese correo. '
                .'Pedile al equipo de Breakfast que la agregue a tu marca.',
        ];
    }

    /**
     * Capped at the owner's own grant — an owner hands out the same or less,
     * never more. See ValidatesSectionPermissions::permissionsPayload().
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return $this->permissionsPayload($this->user());
    }
}
