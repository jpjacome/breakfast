<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\KeepAssistantAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAssistantRequest;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\BrandAssistant;
use App\Services\Ai\BrandContextRepository;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use Illuminate\Http\JsonResponse;

/**
 * The brand's own assistant, answering the brand's own people.
 *
 * ⚠️ THE BRAND COMES FROM THE USER, NEVER FROM THE REQUEST. There is no brand
 * parameter to post: it is whichever of their own brands they are working in
 * (App\Services\ActiveBrand), so there is no slug anyone could swap to read
 * somebody else's. That is the difference from the dashboard assistant, which
 * crosses brands by design and therefore has to be scoped on the way in.
 *
 * ⚠️ Since ACC-01 an account can be in several brands, so the THREAD is keyed
 * on the brand too. Without that, switching brands would replay the previous
 * one's turns into this one's prompt.
 *
 * The context is BrandContextRepository's — the 48 entregables, the brand's
 * ficha and the process. The same source the entregables board writes, so the
 * client can only be told what Breakfast has actually written down.
 *
 * READ-ONLY, like the rest of this side. It answers; it changes nothing.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly BrandAssistant $assistant,
        private readonly BrandContextRepository $context,
        private readonly KeepAssistantAttachments $keep,
    ) {}

    public function __invoke(PortalAssistantRequest $request): JsonResponse
    {
        $user = $request->user();
        $client = $user->activeBrand();

        // A client user with no brand is a broken record, and a brand with
        // nothing written has nothing to answer from. Both fail closed rather
        // than handing the model an empty context and letting it improvise.
        abort_if($client === null, 404);

        if (! $this->context->hasUsableContext($client)) {
            return response()->json([
                'reply' => 'Todavía no hay nada escrito de tu marca. En cuanto el equipo '
                    .'de Breakfast avance, aquí vas a poder preguntarme lo que quieras.',
            ]);
        }

        $question = (string) $request->validated('question');

        try {
            // Attachment::make() refuses what the model cannot open, with a
            // sentence meant for a person. That is a problem with the file,
            // not with the provider, so it answers 422 rather than 502.
            $attachments = $request->attachments();
        } catch (LlmException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        try {
            $response = $this->assistant->answer(
                context: $this->context->for($client),
                question: $question,
                userId: $user->id,
                history: $this->history($user, $client),
                attachments: $attachments,
                speaker: $user->firstName(),
            );
        } catch (LlmException $e) {
            // Reported first, answered second: what the provider actually said
            // belongs in laravel.log, not on a client's screen. See
            // AssistantFailure, which is the one place that decides the
            // difference.
            report($e);

            return response()->json(['error' => AssistantFailure::message($e)], 502);
        }

        // ⚠️ THE CLIENT'S OWN FILES ARE KEPT TOO, and this is the third place
        // the client side writes anything (CLAUDE.md §11). It is narrow on
        // purpose: the row lands as `interno`, so the brand never sees it in
        // its own Archivos — only Breakfast does.
        //
        // The alternative was to drop them, as before, and then a client who
        // pastes a screenshot to ask about it gets an answer discussing a
        // picture that is no longer on the screen. Keeping it is what makes the
        // conversation readable on reload.
        //
        // ⚠️ IT LANDS IN THE CLIENT'S OWN FOLDER (2026-09-17), not their brand's.
        // It used to be a brand_assets row marked interno — visible to Breakfast
        // in the file manager, invisible to the brand, and sitting in the same
        // list as the brand's logo. Breakfast still sees it; it is simply filed
        // under the person who pasted it.
        $kept = $this->keep->handle(
            $user,
            array_values((array) $request->file('files', [])),
        );

        $this->remember(
            $user,
            $client,
            $question,
            $response->content,
            $request->attachmentNames(),
            array_map(fn ($asset) => $asset->id, $kept),
        );

        return response()->json(['reply' => $response->content]);
    }

    /**
     * This person's own last few turns, oldest first.
     *
     * Keyed on the user, so one brand's people do not read each other's
     * conversations either — a member and the owner of the same brand have
     * separate threads.
     *
     * @return array<int, Message>
     */
    private function history(User $user, Client $client): array
    {
        return AssistantMessage::query()
            // ⚠️ Scoped to the brand. One account can be in several (ACC-01),
            // and an unscoped thread would replay brand A's turns into brand
            // B's prompt — see AssistantMessage::scopeThread().
            ->thread($user->id, AssistantMessage::SURFACE_PORTAL, clientId: $client->id)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $m): Message => $m->toLlmMessage())
            ->values()
            ->all();
    }

    /**
     * Written after the answer, so a failed call leaves no half-turn behind.
     *
     * The file NAMES are kept, never the bytes: the transcript has to read
     * right on reload — "mandaste captura.png" — and storing a 40MB voice note
     * in a text column to achieve that would be absurd.
     *
     * @param  array<int, string>  $files
     */
    private function remember(
        User $user,
        Client $client,
        string $question,
        string $answer,
        array $files = [],
        array $assetIds = [],
    ): void {
        foreach ([['user', $question], ['assistant', $answer]] as [$role, $body]) {
            AssistantMessage::create([
                'user_id' => $user->id,
                'surface' => AssistantMessage::SURFACE_PORTAL,
                'role' => $role,
                'body' => $body,
                // The brand the question was asked about — what keys the
                // thread, not merely a label on the row.
                'client_id' => $client->id,
                'attachments' => $role === 'user' && $files !== [] ? $files : null,
                // Names for the model, ids for the screen — see the
                // attachment_ids migration. Only the user's half carries files.
                'attachment_ids' => $role === 'user' && $assetIds !== [] ? $assetIds : null,
            ]);
        }
    }
}
