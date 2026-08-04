<?php

namespace App\Http\Requests;

use App\Enums\ContextDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;

class StoreContextDocumentRequest extends FormRequest
{
    /** Extensions we accept as brand context. */
    public const ALLOWED = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'csv', 'xlsx'];

    public const MAX_KB = 25600; // 25 MB

    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', new Enum(ContextDocumentKind::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required',
                File::types(self::ALLOWED)->max(self::MAX_KB),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'título',
            'kind' => 'tipo',
            'description' => 'descripción',
            'file' => 'archivo',
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'El archivo no puede pesar más de 25 MB.',
        ];
    }
}
