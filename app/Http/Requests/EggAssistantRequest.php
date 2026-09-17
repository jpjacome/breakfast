<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BrandEggLayer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * One turn of the Brand Egg conversation.
 *
 * ⚠️ failedValidation() IS OVERRIDDEN, and it is not optional. A failed
 * validate() on an admin/ route answers with a REDIRECT, which the fetch()
 * driving this panel sees as a silent failure — the panel spins and nothing
 * lands. Trap 13, and the reason three other requests here do the same.
 *
 * `bootstrap/app.php` covers every framework-level failure (419, 404, 429,
 * 500); this covers the one it never reached, which is validation.
 */
class EggAssistantRequest extends FormRequest
{
    /** Behind auth, 'breakfast' and 'covers-client' already. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],

            // ⚠️ Nullable on purpose: "¿por dónde empezamos?" belongs to no
            // ring. Forcing one would make her pick a layer in order to say
            // something general — see the migration's note on the column.
            'layer' => ['nullable', Rule::enum(BrandEggLayer::class)],
        ];
    }

    public function attributes(): array
    {
        return ['message' => 'mensaje'];
    }

    public function layer(): ?BrandEggLayer
    {
        $value = $this->validated('layer');

        return $value === null ? null : BrandEggLayer::from($value);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['errors' => $validator->errors()->toArray()], 422)
        );
    }
}
