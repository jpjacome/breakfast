<?php

namespace App\Http\Requests;

use App\Enums\BrandEggLayer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * One composition run: a single ring, or the whole Egg.
 *
 * `layer` absent means all five in dependency order. That is the first Egg of a
 * brand; the per-ring button is the normal path afterwards, because a brand
 * whose Relato was rewritten needs layers 1 and 3 again, not five calls.
 */
class ComposeBrandEggRequest extends FormRequest
{
    /** Already behind auth, 'breakfast' and 'covers-client'. */
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
            'layer' => ['nullable', Rule::enum(BrandEggLayer::class)],
        ];
    }

    /** The ring to compose, or null for all five. */
    public function layer(): ?BrandEggLayer
    {
        $value = $this->input('layer');

        return is_string($value) && $value !== ''
            ? BrandEggLayer::tryFrom($value)
            : null;
    }

    /**
     * Fail as JSON, not as a redirect.
     *
     * Composing is driven by fetch() — a run is 20 to 100 seconds and the
     * screen has to be able to say which ring it is on — so a redirect here
     * reads on screen as a silent failure. bootstrap/app.php renders JSON for
     * anything that asks for it since 2026-09-15, which covers every failure
     * ABOVE validation; this covers validation itself, which never reaches
     * that handler. CLAUDE.md trap 13, both halves.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
