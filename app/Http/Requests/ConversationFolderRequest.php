<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Creating or renaming one of somebody's own conversation folders — item 5.
 *
 * `name` is required on the way in and `sometimes` on the way through, because
 * a rename and a reorder post to the same endpoint and neither has to carry the
 * other. See ConversationRequest for why absent is not the same as null.
 */
class ConversationFolderRequest extends FormRequest
{
    /** The routes are already behind auth and their side's own middleware. */
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
            // 80 is the column. Longer than a person will type and short enough
            // that the panel never has to decide where to cut.
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:80'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
