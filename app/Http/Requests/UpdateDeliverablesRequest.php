<?php

namespace App\Http\Requests;

use App\Enums\DeliverableItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving the 48 entregables of a brand.
 *
 * Rules are built from DeliverableItem rather than written out, so a new
 * entregable is never silently rejected by a validator nobody remembered to
 * update. Anything not in the enum is not in these rules and therefore never
 * reaches deliverables() below.
 */
class UpdateDeliverablesRequest extends FormRequest
{
    /** The route is already behind auth, 'breakfast' and 'covers-client'. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['entregables' => ['array']];

        foreach (DeliverableItem::cases() as $item) {
            $rules["entregables.{$item->value}"] = ['nullable', 'string', 'max:20000'];
        }

        return $rules;
    }

    /**
     * The 48 values, whitelisted and trimmed, with blanks stored as null.
     *
     * Empty string and null both mean "pending" to BrandDeliverables::has(),
     * so only one of them is kept — otherwise an entregable cleared today
     * leaves an empty string that reads as content tomorrow.
     *
     * Every column is returned, present or not, because this is a full save
     * of the form: an entregable the request does not mention is one somebody
     * emptied, and merging would make it impossible to clear anything.
     *
     * @return array<string, string|null>
     */
    public function deliverables(): array
    {
        $submitted = $this->validated('entregables') ?? [];
        $values = [];

        foreach (DeliverableItem::cases() as $item) {
            $value = trim((string) ($submitted[$item->value] ?? ''));

            $values[$item->value] = $value === '' ? null : $value;
        }

        return $values;
    }
}
