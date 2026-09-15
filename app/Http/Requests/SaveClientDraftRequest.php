<?php

namespace App\Http\Requests;

use App\Enums\DeliverableItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One autosave from /clientes/nueva.
 *
 * Everything is nullable: this fires on a timer against a form somebody is
 * halfway through, so a blank field is the normal case rather than a mistake.
 * The rules exist to cap lengths and to keep anything not on the form out.
 */
class SaveClientDraftRequest extends FormRequest
{
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
            'draft' => ['nullable', 'string', 'max:160'],
            'name' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // The board saves with the form. Approving a proposal is a person
            // accepting it, and losing twenty-three of those to a closed tab is
            // exactly what an autosave exists to prevent.
            'entregables' => ['nullable', 'array'],
            ...collect(DeliverableItem::cases())
                ->mapWithKeys(fn ($item) => [
                    "entregables.{$item->value}" => ['nullable', 'string', 'max:20000'],
                ])->all(),
        ];
    }

    /**
     * The 48 as they stand on screen, or null when the page did not send them.
     *
     * Null and empty are different here: a save that carries no board at all
     * must leave the stored one alone, while a board of blanks is somebody
     * having cleared it. Treating the first as the second would let a stray
     * autosave wipe the work.
     *
     * @return array<string, string|null>|null
     */
    public function deliverables(): ?array
    {
        if (! $this->has('entregables')) {
            return null;
        }

        $submitted = $this->validated('entregables') ?? [];
        $values = [];

        foreach (DeliverableItem::cases() as $item) {
            $value = trim((string) ($submitted[$item->value] ?? ''));
            $values[$item->value] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * The brand's own columns, without the draft pointer riding along.
     *
     * contact_email is deliberately NOT validated as an email here. An autosave
     * catches somebody mid-typing, and rejecting "maria@" would make the save
     * fail silently every few seconds while they finish the word. The real
     * check happens on StoreClientRequest when the brand is finished.
     *
     * @return array<string, mixed>
     */
    public function draftAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['name', 'industry', 'contact_name', 'contact_email', 'notes']),
        );
    }

    /**
     * Fail as JSON — bootstrap/app.php renders JSON only for api/* paths, so
     * without this a rejected autosave answers a fetch() with a redirect.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
