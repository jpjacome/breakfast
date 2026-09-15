<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // public form
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            'about_brand' => ['nullable', 'string', 'max:5000'],

            // Honeypot: hidden from people, irresistible to bots. Anything in
            // it means the submission was not typed by a human.
            'website' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => 'nombre',
            'last_name' => 'apellido',
            'email' => 'email',
            'phone' => 'whatsapp o teléfono',
            'about_brand' => 'sobre tu marca',
        ];
    }
}
