<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSectionPermissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Breakfast staff changing what a brand's user can reach — most often the
 * brand owner, since that is where a brand's whole ceiling is set.
 */
class UpdateClientUserRequest extends FormRequest
{
    use ValidatesSectionPermissions;

    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return $this->permissionRules();
    }

    /** @return array<string, string> */
    public function permissions(): array
    {
        // Breakfast staff hold Write everywhere, so nothing is capped here.
        // The cap exists for the owner granting their own team.
        return $this->permissionsPayload($this->user());
    }
}
