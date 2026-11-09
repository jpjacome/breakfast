<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Anything the history panel posts about one conversation — item 5.
 *
 * ⚠️ EVERY FIELD IS `sometimes`, and that is the shape of the PATCH rather
 * than laziness: absent means "leave it alone" and null means "clear it". A
 * rule of `nullable` alone would make a rename that omits `folder` look
 * identical to one that empties it, and somebody's folders would quietly
 * unfile themselves every time they corrected a title.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT MAY BE TOUCHED. The ids are hints; the
 * controller resolves each one and 404s on anything that is not this person's,
 * on this surface, in this brand. Validation says the shape is right, never
 * that the row is theirs.
 */
class ConversationRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'folder' => ['sometimes', 'nullable', 'integer'],
            'archived' => ['sometimes', 'boolean'],
            // What the meter's edit box sends back. Long, because it is the
            // model's whole memory of the beginning of the conversation.
            'summary' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ];
    }

    /**
     * Fail as JSON, not as a redirect.
     *
     * The panel is driven by fetch(), so a redirect reaches it as a silent
     * failure — the same reason the three assistant requests override this
     * (trap 13).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
