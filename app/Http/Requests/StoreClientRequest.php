<?php

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:80'],
            'status' => ['required', new Enum(ClientStatus::class)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre de la marca',
            'industry' => 'industria',
            'status' => 'estado',
            'contact_name' => 'nombre de contacto',
            'contact_email' => 'correo de contacto',
            'notes' => 'notas',
        ];
    }
}
