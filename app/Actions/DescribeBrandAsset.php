<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssetSource;
use App\Models\BrandAsset;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\UsageRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turn one image in a brand's folder into text, once.
 *
 * ⚠️ WHY THIS EXISTS AT ALL: the Brand Egg is composed from database fields of
 * the brand and never from a picture (docs/brand-egg.md §1). A logo or a colour
 * sheet is a file, so layer 4 — "Brand Assets / Icons" — had nothing visual it
 * was allowed to read. This is what makes a picture into a field.
 *
 * ⚠️ AND IT IS NOT THE TOOLKIT DIGEST. `clients.document_digest` is the model's
 * unreviewed reading of an uploaded PDF and sits at the BOTTOM of the
 * assistant's five tiers precisely because nobody checked it. What this writes
 * hangs off a row somebody filed on purpose, shows beside the file, and can be
 * corrected — which is what makes it brand data rather than background.
 *
 * ⚠️ NEVER THROWS. Same contract as KeepAssistantAttachments, and for a sharper
 * reason: this runs inside an upload. A provider outage, a 50MB file or a
 * missing disk must leave the file uploaded and the row intact — a description
 * that failed is an empty column somebody can fill later, while an exception
 * would lose the upload the person actually asked for.
 */
final class DescribeBrandAsset
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Read one asset and store the description. True when something was written.
     *
     * ⚠️ IMAGES ONLY, AND ONLY `subida`. A PDF filed here is a brandbook, and
     * the onboarding assistant already reads those into the digest — doing it
     * twice would pay twice for two readings that can disagree. And a
     * `referencia` is a screenshot that fell out of a conversation: it is in
     * the folder so the chat can still show it, not because anybody decided it
     * describes the brand. Paying to describe those is the cost with none of
     * the value.
     */
    public function handle(BrandAsset $asset, ?int $userId = null): bool
    {
        if (! $this->shouldRead($asset)) {
            return false;
        }

        try {
            $contents = Storage::disk($asset->disk)->get($asset->path);
        } catch (\Throwable $e) {
            // A row whose file is gone is a known state elsewhere in this app
            // (the download route 404s on it). Not worth an exception here.
            report($e);

            return false;
        }

        if ($contents === null || $contents === '') {
            return false;
        }

        try {
            $attachment = Attachment::make(
                $asset->original_name,
                (string) $asset->mime,
                $contents,
            );
        } catch (\Throwable $e) {
            // Refused by Attachment — too big, or a mime the provider 400s on.
            // Its own rules, deliberately not second-guessed here.
            Log::info('Asset not describable', [
                'asset' => $asset->id,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        $messages = [
            // The cached prefix: identical for every image of every brand, so
            // a folder of thirty files pays for these instructions once.
            Message::cacheableSystem((string) config('ai.asset_reading_prompt')),
            Message::userWithAttachments($this->turn($asset), [$attachment]),
        ];

        try {
            $response = $this->client->complete($messages, role: 'content', options: [
                // Describing, not composing. The default is set for brand copy
                // and here it embellishes what is in front of it.
                'temperature' => 0.1,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $asset->client_id,
                userId: $userId,
                operation: 'asset-reading',
                model: $this->client->modelFor('content'),
            );

            return false;
        }

        $this->usage->record(
            $response,
            clientId: $asset->client_id,
            userId: $userId,
            operation: 'asset-reading',
        );

        $reading = trim($response->content);

        if ($reading === '') {
            return false;
        }

        $asset->forceFill([
            'visual_reading' => $reading,
            'read_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Whether this asset is one we read, and has not been read already.
     *
     * ⚠️ `read_at` VERSUS `updated_at` IS THE STALENESS CHECK. A row whose file
     * was replaced keeps a description of the old picture, and comparing the
     * two timestamps is the only way to notice — there is no other signal that
     * the bytes behind a path changed.
     */
    public function shouldRead(BrandAsset $asset): bool
    {
        if ($asset->source !== AssetSource::Subida || ! $asset->isImage()) {
            return false;
        }

        if ($asset->read_at === null) {
            return true;
        }

        return $asset->updated_at !== null && $asset->updated_at->greaterThan($asset->read_at);
    }

    /**
     * Block 3: which file this is.
     *
     * The title is what a person typed when they filed it, so it is the one
     * piece of human intent available — "Emblema principal" tells the model
     * what it is looking at in a way the pixels do not. It is per-asset and
     * therefore belongs here, below the cached prefix, never in it.
     */
    private function turn(BrandAsset $asset): string
    {
        return 'ARCHIVO: '.$asset->title
            ."\nNombre original: ".$asset->original_name
            ."\n\nDescribe esta imagen.";
    }
}
