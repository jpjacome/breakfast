<?php

namespace App\Http\Requests;

use App\Enums\PortalSection;
use App\Http\Requests\Concerns\ValidatesSectionPermissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing what an existing teammate can reach. Same grid as the invite form,
 * without the identity fields — a member's name and email are theirs to
 * change in /portal/perfil, not the owner's.
 */
class UpdateTeamMemberRequest extends FormRequest
{
    use ValidatesSectionPermissions;

    public function authorize(): bool
    {
        return $this->user()?->canWrite(PortalSection::Equipo) ?? false;
    }

    public function rules(): array
    {
        return $this->permissionRules();
    }

    /**
     * Capped at the owner's own grant, same as the invite form.
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return $this->permissionsPayload($this->user());
    }
}
