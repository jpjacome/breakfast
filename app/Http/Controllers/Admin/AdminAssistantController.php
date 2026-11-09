<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\KeepAssistantAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminAssistantRequest;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Services\Ai\ActiveConversation;
use App\Services\Ai\AdminAssistant;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\ConversationReferences;
use App\Services\Ai\Exceptions\LlmException;
use Illuminate\Http\JsonResponse;

/**
 * The dashboard assistant's endpoint.
 *
 * The dropdown on that screen is the mode switch: no brand means "todas las
 * marcas" and the question is answered from the portfolio snapshot; a brand
 * appends that brand's ficha.
 *
 * READ-ONLY. This is a POST only because it carries a question and costs money;
 * it changes nothing.
 */
class AdminAssistantController extends Controller
{
    public function __construct(
        private readonly AdminAssistant $assistant,
        private readonly KeepAssistantAttachments $keep,
        private readonly ActiveConversation $conversations,
        private readonly ConversationReferences $references,
    ) {}

    public function __invoke(AdminAssistantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $client = null;

        if (! empty($validated['brand'])) {
            // visibleTo, not findBySlug: an Equipo member hand-posting another
            // brand's slug must not get its ficha. Unknown or unreachable
            // slugs fall through to portfolio mode rather than erroring —
            // the snapshot is already scoped, so the answer stays correct.
            $client = Client::query()
                ->visibleTo($user)
                ->where('slug', $validated['brand'])
                ->first();
        }

        try {
            // A file the model cannot open is a problem with the file, not
            // with the provider — 422, not 502.
            $attachments = $request->attachments();
        } catch (LlmException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        /*
         * ⚠️ RESOLVED BEFORE THE ANSWER, so a bad id costs nothing. This is the
         * one field of the request somebody could hand-post at another
         * person's thread, and ActiveConversation 404s rather than falling back
         * — checking it after a paid call would mean paying for the refusal.
         *
         * ⚠️ NO BRAND IS PASSED, deliberately. The dashboard's conversation
         * crosses brands; the dropdown says what THIS question is about, not
         * which thread it belongs to.
         */
        $conversation = $this->conversations->for(
            $user,
            AssistantMessage::SURFACE_ADMIN,
            requested: $validated['conversation'] ?? null,
        );

        // Kept BEFORE the answer, and kept even if the answer fails: the bytes
        // are already on this server, the turn has already been paid for, and
        // losing the file because the provider was down would mean uploading it
        // again — which is the whole thing this removes.
        //
        // ⚠️ IT GOES TO THE PERSON'S FOLDER, NOT A BRAND'S (2026-09-17). It used
        // to need a brand to file under — and with no brand chosen here, an
        // "unfiled" folder beside the real ones. Neither question arises now:
        // what somebody pastes is theirs, and the brand dropdown has nothing to
        // do with it.
        $kept = $this->keep->handle(
            $user,
            array_values((array) $request->file('files', [])),
        );

        try {
            $response = $this->assistant->answer(
                $user,
                (string) ($validated['question'] ?? ''),
                $client,
                $attachments,
                $request->attachmentNames(),
                array_map(fn ($asset) => $asset->id, $kept),
                $conversation,
                // Anything the question pointed at with #conv-N, resolved
                // through the same ownership check as the panel itself — an id
                // that is not theirs is dropped in silence, never refused.
                $this->references->digest(
                    $user,
                    AssistantMessage::SURFACE_ADMIN,
                    null,
                    (string) ($validated['question'] ?? ''),
                    $conversation,
                ),
            );
        } catch (LlmException $e) {
            report($e);

            return response()->json(
                ['error' => AssistantFailure::message($e, forStaff: true)],
                502,
            );
        }

        return response()->json([
            'reply' => $response->content,
            'brand' => $client?->name,
            // The panel needs both back: the id because a first question may
            // have just created the conversation, and the title because it was
            // written from that same question.
            'conversation' => $conversation->id,
            'title' => $conversation->fresh()->title,
        ]);
    }
}
