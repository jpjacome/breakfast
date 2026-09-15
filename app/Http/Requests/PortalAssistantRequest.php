<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsAssistantFiles;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One question from a brand's own person to their assistant.
 *
 * ⚠️ failedValidation() is overridden because bootstrap/app.php renders JSON
 * only for api/*. On a portal/ route a failed validate() answers with a
 * REDIRECT, which the fetch() in assistant.js sees as a silent failure — the
 * orb spins and nothing ever lands. Trap 13.
 */
class PortalAssistantRequest extends FormRequest
{
    use AcceptsAssistantFiles;

    /** The route is behind auth already; the controller checks the brand. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required unless something came with it: a voice note IS the
            // question, and asking somebody to type one as well defeats the
            // point of recording it.
            'question' => [$this->hasFile('files') ? 'nullable' : 'required', 'string', 'max:1000'],
            ...$this->fileRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'Escribe una pregunta.',
            'question.max' => 'La pregunta es muy larga. Resúmela en menos de :max caracteres.',
            ...$this->fileMessages(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['question' => trim((string) $this->input('question'))]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['errors' => $validator->errors()->toArray()], 422)
        );
    }
}
