<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Archiving or destroying a brand.
 *
 * ADMIN ONLY. An Equipo member works on the brands they were put on; removing
 * one is not working on it. This is the same split as StaffController, where
 * everybody sees the roster and only an Admin changes it.
 *
 * The brand's name has to be typed. It is the only guard that survives muscle
 * memory — a confirm dialog on the fourth row of a list gets dismissed without
 * being read, and this operation takes the entregables, the files, the meetings
 * and the client's people with it.
 */
class DeleteClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string'],
        ];
    }

    /**
     * The typed name must match, ignoring case and stray spaces.
     *
     * Not a strict comparison: somebody copying "The Coffee Club " out of the
     * page should not be told they got their own brand's name wrong.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $client = $this->route('client');
                $typed = mb_strtolower(trim((string) $this->input('confirmation')));

                if ($client === null || $typed === mb_strtolower(trim($client->name))) {
                    return;
                }

                $validator->errors()->add(
                    'confirmation',
                    'Escribe el nombre de la marca exactamente para confirmar.',
                );
            },
        ];
    }
}
