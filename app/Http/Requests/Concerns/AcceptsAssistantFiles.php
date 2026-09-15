<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Exceptions\LlmException;

/**
 * Files sent with a question to Brandy — a pasted screenshot, a voice note.
 *
 * Shared by both surfaces because they accept exactly the same things: one
 * agent, one set of formats. The rules that matter live on Attachment, which
 * knows what the provider can actually open and what it costs.
 *
 * ⚠️ TWO IS THE CAP, not the four the onboarding assistant allows. That one is
 * handed a brandbook and a deck at once and is expected to read them; this is
 * a chat, where somebody pastes a screenshot and asks what Brandy thinks. A
 * dozen images inlined into one turn is a bill, not a question.
 */
trait AcceptsAssistantFiles
{
    /** Rules to merge into the request's own. */
    protected function fileRules(): array
    {
        return [
            'files' => ['nullable', 'array', 'max:2'],
            'files.*' => [
                'file',
                'mimetypes:'.implode(',', Attachment::acceptedMimes()),
                'max:'.(Attachment::MAX_AUDIO_BYTES / 1024),
            ],
        ];
    }

    protected function fileMessages(): array
    {
        return [
            'files.max' => 'Máximo :max archivos por mensaje.',
            'files.*.mimetypes' => 'Puedo leer imágenes, PDF y audio. Ese formato no.',
            'files.*.max' => 'Ese archivo pesa demasiado.',
        ];
    }

    /**
     * The uploads as Attachments.
     *
     * ⚠️ Attachment::make() applies the per-kind size cap and refuses a format
     * the model cannot open, throwing LlmException with a sentence meant for a
     * person. Caught by the caller and returned as 422 — it is a problem with
     * the file, not with the provider, and must not read as one.
     *
     * @return array<int, Attachment>
     *
     * @throws LlmException
     */
    public function attachments(): array
    {
        return array_map(
            static fn ($file): Attachment => Attachment::fromUploadedFile($file),
            array_values((array) $this->file('files', [])),
        );
    }

    /** What the transcript records came with the turn. */
    public function attachmentNames(): array
    {
        return array_map(
            static fn ($file): string => (string) $file->getClientOriginalName(),
            array_values((array) $this->file('files', [])),
        );
    }
}
