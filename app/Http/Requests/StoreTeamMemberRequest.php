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
            /*
             * ⚠️ NO unique RULE, AND ITS ABSENCE IS THE FIX — queued item B.
             *
             * It used to refuse a known address with "ya existe una cuenta con
             * ese correo", while the comment below claimed to be preventing an
             * owner from learning exactly that. The refusal WAS the leak.
             *
             * An address with an account now produces an invitation that person
             * answers, and the owner is told the same sentence either way. See
             * InviteUserToClient::invitePending().
             */
            'email' => ['required', 'email', 'max:190'],
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
