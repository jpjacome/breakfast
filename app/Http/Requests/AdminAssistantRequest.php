<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsAssistantFiles;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One question to the dashboard assistant.
 *
 * `brand` is the dropdown: empty means "todas las marcas", which is the default
 * and the mode most questions arrive in. The slug is only a hint — the
 * controller still resolves it through Client::visibleTo(), so a hand-posted
 * slug cannot fetch a brand this user may not see.
 */
class AdminAssistantRequest extends FormRequest
{
    use AcceptsAssistantFiles;

    /** The route is already behind auth + the 'breakfast' middleware. */
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
            // question, and typing one as well defeats recording it.
            'question' => [$this->hasFile('files') ? 'nullable' : 'required', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:120'],
            ...$this->fileRules(),
        ];
    }

    /**
     * Fail as JSON, not as a redirect.
     *
     * bootstrap/app.php only renders JSON for api/* paths, and this endpoint
     * lives under admin/ because it is part of the admin session, not an API.
     * Without this override a rejected question sends a redirect to a fetch()
     * call, which reads as a silent failure on screen.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
