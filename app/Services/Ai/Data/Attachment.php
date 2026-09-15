<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Services\Ai\Exceptions\InvalidRequest;
use Illuminate\Http\UploadedFile;

/**
 * A file handed to the model as part of a message.
 *
 * Brand references do not arrive as markdown. They arrive as a brandbook PDF,
 * a screenshot of a deck, a photo of a printed guide, a voice note explaining
 * why the typeface was chosen. A text-only model cannot read any of those,
 * which is the whole reason the provider had to change.
 *
 * THERE IS NO TRANSCRIPTION STEP. No OCR service, no speech-to-text call, no
 * PDF parser. The model reads the file itself, in the same request that maps
 * it onto the 42 fields — which is exactly why the provider had to be one that
 * takes images, documents and audio natively. A separate transcribe-then-read
 * pipeline would cost two calls, lose the layout of a brandbook page and the
 * tone of a voice note, and give the extraction nothing to cite back to.
 *
 * Files are inlined as data URIs rather than uploaded first: it keeps the flow
 * to one request, and a brandbook is a one-off read, not something worth
 * managing the lifecycle of on a remote store.
 *
 * WIRE FORMAT — not yet verified against a live call, because there are no
 * OpenRouter credentials yet. The three shapes below are what OpenAI and
 * OpenRouter document for chat-completions requests. If a first real call
 * comes back rejecting a part, this class is the only place that changes.
 */
final readonly class Attachment
{
    /** What the model can actually open. Anything else is refused up front. */
    public const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const DOCUMENT_MIMES = ['application/pdf'];

    /**
     * Voice notes, mostly. The team records one walking out of a workshop and
     * it says more about the brand's tone in ninety seconds than a written
     * summary does in a page.
     *
     * m4a and ogg are here because that is what a phone produces: iPhone voice
     * memos are m4a, WhatsApp notes are ogg. Leaving them out would mean the
     * two most likely files in the world for this feature are the two it
     * refuses.
     */
    public const AUDIO_MIMES = [
        'audio/mpeg',    // .mp3
        'audio/mp4',     // .m4a
        'audio/x-m4a',   // .m4a, as some browsers label it
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/flac',
        'audio/aac',
        'audio/aiff',
        'audio/x-aiff',
    ];

    /**
     * 50MB, because that is where GEMINI stops — not a number we chose.
     *
     * Verified 2026-08-13: inline data was raised from 20MB to 100MB in
     * January 2026, but PDFs keep their own ceiling of 50MB or 1000 pages, and
     * it applies to the Files API too. Setting this higher would let a 90MB
     * brandbook upload for a minute and then fail at the provider, which is a
     * worse answer than refusing it in the browser before it leaves.
     *
     * ⚠️ SIZE IS NOT COST. Gemini bills a PDF per PAGE — about 258 tokens each
     * as an image — and text natively embedded in the file is extracted for
     * free. A 90MB and a 4MB copy of the same sixty pages cost the same; the
     * extra megabytes are print-resolution imagery that gets downsampled before
     * the model sees it. So a big file is a transport problem, never a bill.
     *
     * ⚠️ DO NOT "FIX" THIS BY RASTERISING PDFs to shrink them. Turning a text
     * layer into images loses the free extraction and bills every page as an
     * image instead: more expensive AND a worse read. The only compression
     * that helps downsamples the images and keeps the text, which needs
     * Ghostscript — not available on this host.
     */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /**
     * 100MB, the inline-data ceiling — bigger than MAX_BYTES, which is not a
     * mistake. The 50MB above is the PDF-specific limit; audio only has to fit
     * inside the general one.
     *
     * It is the larger of the two, which is why BrandOnboardingTurnRequest
     * validates every upload against THIS and lets Attachment::make() apply the
     * stricter per-kind cap afterwards — make() knows whether it is holding a
     * voice note or a brandbook and can say which limit was hit.
     */
    public const MAX_AUDIO_BYTES = 100 * 1024 * 1024;

    /**
     * Private: make() is the only way in, because it is the only place that
     * checks the mime and the size. A constructor anyone could call would let
     * an unreadable file reach the provider and fail there instead of here.
     *
     * $base64 is the payload already encoded — it goes into a data URI or an
     * audio part verbatim, and decoding it just to re-encode would double the
     * memory a 50MB brandbook costs.
     */
    private function __construct(
        public string $filename,
        public string $mime,
        public string $base64,
    ) {}

    public static function fromUploadedFile(UploadedFile $file): self
    {
        return self::make(
            filename: $file->getClientOriginalName(),
            mime: (string) $file->getMimeType(),
            contents: (string) file_get_contents($file->getRealPath()),
        );
    }

    /** Everything the assistant will accept, in one list. */
    public static function acceptedMimes(): array
    {
        return [...self::IMAGE_MIMES, ...self::DOCUMENT_MIMES, ...self::AUDIO_MIMES];
    }

    public static function make(string $filename, string $mime, string $contents): self
    {
        $mime = mb_strtolower(trim($mime));

        if (! in_array($mime, self::acceptedMimes(), strict: true)) {
            throw new InvalidRequest(
                "El asistente no puede leer archivos {$mime}. Acepta PDF, imágenes "
                .'(PNG, JPG, WEBP, GIF) y audio (MP3, M4A, WAV, OGG).'
            );
        }

        $attachment = new self($filename, $mime, '');
        $limit = $attachment->isAudio() ? self::MAX_AUDIO_BYTES : self::MAX_BYTES;

        if (strlen($contents) > $limit) {
            throw new InvalidRequest(sprintf(
                '«%s» pesa más de %dMB. %s',
                $filename,
                $limit / 1024 / 1024,
                $attachment->isAudio()
                    ? 'Córtalo en tramos más cortos.'
                    : 'Divídelo o sube solo las páginas que importan.',
            ));
        }

        return new self($filename, $mime, base64_encode($contents));
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::IMAGE_MIMES, strict: true);
    }

    public function isAudio(): bool
    {
        return in_array($this->mime, self::AUDIO_MIMES, strict: true);
    }

    public function dataUri(): string
    {
        return "data:{$this->mime};base64,{$this->base64}";
    }

    /**
     * The container name the audio part wants.
     *
     * OpenRouter documents exactly this set: wav, mp3, aiff, aac, ogg, flac,
     * m4a, pcm16, pcm24 (verified 2026-08-12). Every value produced here is on
     * that list, and every mime accepted above maps to one — which is why
     * webm is NOT accepted, despite browsers producing it happily. Sending a
     * format off the list is a 400, so it is refused at upload instead.
     */
    private function audioFormat(): string
    {
        return match ($this->mime) {
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/aiff', 'audio/x-aiff' => 'aiff',
            default => 'mp3',
        };
    }

    /**
     * The content part for an OpenAI-compatible request.
     *
     * Three shapes, one per kind. Audio carries raw base64 rather than a data
     * URI — that is the one place the format differs, and getting it wrong is
     * a 400 rather than a bad answer.
     *
     * @return array<string, mixed>
     */
    public function toContentPart(): array
    {
        if ($this->isImage()) {
            return [
                'type' => 'image_url',
                'image_url' => ['url' => $this->dataUri()],
            ];
        }

        if ($this->isAudio()) {
            return [
                'type' => 'input_audio',
                'input_audio' => [
                    'data' => $this->base64,
                    'format' => $this->audioFormat(),
                ],
            ];
        }

        return [
            'type' => 'file',
            'file' => [
                'filename' => $this->filename,
                'file_data' => $this->dataUri(),
            ],
        ];
    }
}
