<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\KeepAssistantAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAssistantRequest;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\ActiveConversation;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\BrandAssistant;
use App\Services\Ai\BrandContextRepository;
use App\Services\Ai\ConversationReferences;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\SummarisesConversations;
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
        private readonly ActiveConversation $conversations,
        private readonly SummarisesConversations $summariser,
        private readonly ConversationReferences $references,
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

        /*
         * ⚠️ RESOLVED BEFORE THE ANSWER, so a bad id costs nothing — and
         * SCOPED TO THE ACTIVE BRAND. An id belonging to this person's OTHER
         * brand is a 404 here, which is the same rule the thread already
         * followed: without it, switching brands and continuing would replay
         * one brand's turns into the other's prompt (ACC-01).
         */
        $conversation = $this->conversations->for(
            $user,
            AssistantMessage::SURFACE_PORTAL,
            $client,
            $request->validated('conversation'),
        );

        try {
            $response = $this->assistant->answer(
                context: $this->context->for($client),
                // ⚠️ The referenced conversations ride on the question and are
                // NOT remembered with it: remember() below gets the raw one, so
                // the transcript shows what the client typed rather than a
                // digest they never wrote — and so it is not replayed, and
                // re-paid for, on every later turn.
                question: $question.($this->references->digest(
                    $user,
                    AssistantMessage::SURFACE_PORTAL,
                    $client->id,
                    $question,
                    $conversation,
                ) ?? ''),
                userId: $user->id,
                history: $this->history($user, $client, $conversation),
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
            $conversation,
        );

        // ⚠️ AFTER THE ANSWER AND IT NEVER THROWS: folding the old part up is a
        // second paid call, and a hiccup in it must not turn an answer the
        // client is already looking at into a 502.
        $conversation->noteActivity();
        $this->summariser->handle($conversation);

        return response()->json([
            'reply' => $response->content,
            'conversation' => $conversation->id,
            'title' => $conversation->fresh()->title,
        ]);
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
    private function history(User $user, Client $client, ?Conversation $conversation = null): array
    {
        /*
         * ⚠️ THE CONVERSATION DECIDES WHAT IS REPLAYED, not a count of rows —
         * item 5. replayable() returns everything since the summary, and the
         * summary carries the beginning, so a long afternoon is no longer
         * silently truncated at twenty turns.
         *
         * The scopeThread() path stays for a turn from a page that has not been
         * reloaded since the panel shipped. It is scoped to the brand for the
         * reason it always was: one account can be in several (ACC-01), and an
         * unscoped thread replays brand A's turns into brand B's prompt.
         */
        $turns = $conversation !== null
            ? $conversation->replayable()
            : AssistantMessage::query()
                ->thread($user->id, AssistantMessage::SURFACE_PORTAL, clientId: $client->id)
                ->get()
                ->reverse();

        $history = $turns
            ->map(fn (AssistantMessage $m): Message => $m->toLlmMessage())
            ->values()
            ->all();

        $summary = trim((string) $conversation?->summary);

        return $summary === ''
            ? $history
            : [Message::user('Resumen de lo anterior en esta conversación:'.'

'.$summary), ...$history];
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
        ?Conversation $conversation = null,
    ): void {
        $conversation?->titleFrom($question);

        foreach ([['user', $question], ['assistant', $answer]] as [$role, $body]) {
            AssistantMessage::create([
                // Which conversation this turn belongs to — item 5.
                'conversation_id' => $conversation?->id,
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
