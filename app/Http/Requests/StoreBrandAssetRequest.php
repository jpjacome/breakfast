<?php

namespace App\Http\Requests;

use App\Enums\AssetVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading files into a brand's folder.
 *
 * Several at once, because that is how assets arrive: a logo pack is eight
 * files, not one, and making somebody upload them one at a time is how half of
 * them never get uploaded.
 */
class StoreBrandAssetRequest extends FormRequest
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
        return [
            'files' => ['required', 'array', 'max:20'],
            // No mime whitelist: this is a brand's own folder, filled by
            // Breakfast staff, and the file is never executed — it is streamed
            // back by a controller. Refusing an unusual format would only mean
            // somebody zips it and uploads that instead.
            // 200MB. ⚠️ NOT THE SAME LIMIT AS THE ASSISTANT'S, and raising this
            // must not drag that one with it. These bytes go to disk and come
            // back through a download route; Attachment::MAX_BYTES governs what
            // is sent to the AI on every read, costs money per upload, and is
            // what produces the 60–150s request that starves the worker pool
            // (CLAUDE.md §3). They are different numbers because they are
            // different problems.
            //
            // Was 50MB, which a packaged .ai or a Keynote deck passes routinely
            // — ARC-01 of the beta review. The host allows 512M, so this is our
            // ceiling rather than the server's.
            'files.*' => ['file', 'max:204800'],
            'title' => ['nullable', 'string', 'max:160'],

            // Who the file is for. Nullable so an older form, or a request
            // built by hand, still uploads — it just gets the safe-for-the-
            // client default rather than silently becoming internal.
            'visibility' => ['nullable', Rule::enum(AssetVisibility::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Elige al menos un archivo.',
            'files.max' => 'Máximo :max archivos a la vez.',
            'files.*.max' => 'Cada archivo tiene que pesar menos de 200 MB.',
        ];
    }

    /**
     * Who may see what is being uploaded.
     *
     * Defaults to Compartido, which is what every file in this app was before
     * the choice existed. The direction that costs something — a contract in
     * front of the client — is the one that has to be chosen on purpose.
     */
    public function visibility(): AssetVisibility
    {
        return AssetVisibility::tryFrom((string) $this->validated('visibility'))
            ?? AssetVisibility::default();
    }

    /**
     * The name to file one upload under.
     *
     * A title typed once is applied to a single upload; with several files it
     * would label them all the same, so each keeps its own filename instead.
     */
    public function titleFor(string $originalName, bool $single): string
    {
        $typed = trim((string) $this->validated('title'));

        if ($single && $typed !== '') {
            return $typed;
        }

        return pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName;
    }
}
