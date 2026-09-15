<?php

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Models\Client;
use App\Services\Ai\Brand\DeliverableExtractor;
use App\Services\Ai\Data\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * One message to the onboarding assistant: text, files, or both.
 *
 * ⚠️ AND ONE OTHER SHAPE, which carries no message at all: a proposal batch.
 * An upload is answered in two phases — the files are read once, then the
 * proposals are asked for a slice of the board at a time — because doing both
 * in one generation outran what this host allows a request to live. Those
 * follow-up calls send `batch` and nothing else, so the "say something or
 * attach something" rule below has to let them through.
 */
class BrandOnboardingTurnRequest extends FormRequest
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
            'message' => ['nullable', 'string', 'max:8000'],

            // ⚠️ TWO, LOWERED FROM FOUR on 2026-08-18. Every file is inlined
            // into the same request and read in the same generation, so four
            // brandbooks is not a bigger batch — it is one request holding a
            // PHP worker for minutes, and this host answers everything else
            // with a 503 while it does. Two is what fits. Keep it in step with
            // MAX_FILES in resources/js/process-assistant.js, which says no
            // before the upload rather than after it.
            'files' => ['nullable', 'array', 'max:2'],
            'files.*' => [
                'file',
                'mimetypes:'.implode(',', Attachment::acceptedMimes()),
                // The larger of the two caps. The per-kind limit is enforced in
                // Attachment::make(), which knows whether it is looking at a
                // voice note or a screenshot and can say so.
                'max:'.(Attachment::MAX_AUDIO_BYTES / 1024),
            ],

            // Unsaved form state, so the assistant sees what the person is
            // looking at rather than what was last written to the database.
            'form' => ['nullable', 'array'],
            'form.*' => ['nullable', 'string', 'max:20000'],

            // /clientes/nueva only: which draft this turn belongs to, and the
            // brand form beside the assistant, so a name typed there names the
            // draft rather than leaving it as the placeholder.
            'draft' => ['nullable', 'string', 'max:160'],

            // Which slice of the 48 this call is for. Absent on an ordinary
            // turn; present, and alone, on the calls that follow an upload.
            'batch' => ['nullable', 'integer', 'min:0', 'max:'.(DeliverableExtractor::batchCount() - 1)],

            // Which assistant turn this batch's cards belong to, so a reading
            // survives the tab being closed. Checked against the brand before
            // anything is written — see keepProposals().
            'turn' => ['nullable', 'integer', 'min:1'],
            'brand' => ['nullable', 'array'],
            'brand.name' => ['nullable', 'string', 'max:120'],
            'brand.industry' => ['nullable', 'string', 'max:80'],
            'brand.contact_name' => ['nullable', 'string', 'max:120'],
            'brand.contact_email' => ['nullable', 'string', 'max:190'],
            'brand.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.mimetypes' => 'El asistente lee PDF, imágenes (PNG, JPG, WEBP, GIF) '
                .'y audio (MP3, M4A, WAV, OGG).',
            'files.*.max' => 'Cada archivo tiene que pesar menos de :max KB.',
            'files.max' => 'Máximo :max archivos por mensaje.',
        ];
    }

    /**
     * A turn with neither text nor files is nothing to answer. Caught here so
     * it costs a validation error instead of an API call.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // A proposal batch is a machine asking for the next slice of a
                // reading that already happened. There is nothing for a person
                // to have typed.
                if ($this->isProposalBatch()) {
                    return;
                }

                if (trim((string) $this->input('message')) === '' && ! $this->hasFile('files')) {
                    $validator->errors()->add('message', 'Escribe algo o adjunta un archivo.');
                }
            },
        ];
    }

    /**
     * Fail as JSON, not as a redirect.
     *
     * bootstrap/app.php only renders JSON for api/* paths, and this endpoint
     * lives under admin/ because it is part of the admin session, not an API.
     * Without this override a rejected file would send a redirect to a fetch()
     * call, which reads as a silent failure on screen.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /** Is this one of the calls that follows an upload, rather than a message? */
    public function isProposalBatch(): bool
    {
        return $this->has('batch') && $this->input('batch') !== null;
    }

    public function batchIndex(): int
    {
        return (int) $this->input('batch', 0);
    }

    /**
     * The assistant turn these proposals hang off, if the browser named one.
     *
     * Null for a typed turn and for any batch from a browser that has not been
     * reloaded since this shipped — both of which still work, they just do not
     * keep their cards.
     */
    public function turnId(): ?int
    {
        $turn = $this->input('turn');

        return $turn === null || $turn === '' ? null : (int) $turn;
    }

    /**
     * The entregables this call is responsible for.
     *
     * Empty when the index is past the end, which is not an error worth a 422:
     * a browser that asks for a fifth batch of four gets an empty tray and
     * stops, which is what it would do anyway.
     *
     * @return array<int, DeliverableItem>
     */
    public function batchSlice(): array
    {
        return DeliverableExtractor::batches()[$this->batchIndex()] ?? [];
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            static fn (UploadedFile $file): Attachment => Attachment::fromUploadedFile($file),
            $this->file('files') ?? [],
        );
    }

    /**
     * The draft this turn belongs to, on /clientes/nueva.
     *
     * Resolved through visibleTo and checked for Borrador: a hand-posted slug
     * must not point the assistant at a live brand and let it write there.
     */
    public function existingDraft(): ?Client
    {
        $slug = trim((string) $this->validated('draft'));

        if ($slug === '') {
            return null;
        }

        return Client::query()
            ->visibleTo($this->user())
            ->where('slug', $slug)
            ->where('status', ClientStatus::Borrador)
            ->first();
    }

    /**
     * The brand form beside the assistant on /clientes/nueva.
     *
     * @return array<string, mixed>
     */
    public function draftAttributes(): array
    {
        return array_filter((array) ($this->validated('brand') ?? []));
    }

    /**
     * The board as it currently stands on screen, keys whitelisted against the
     * enum so an extra input in the payload can never invent an entregable.
     *
     * @return array<string, string>
     */
    public function formValues(): array
    {
        $submitted = $this->validated('form') ?? [];
        $values = [];

        foreach (DeliverableItem::cases() as $item) {
            if (array_key_exists($item->value, $submitted)) {
                $values[$item->value] = trim((string) $submitted[$item->value]);
            }
        }

        return $values;
    }
}
