<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConversationRequest;
use App\Models\AssistantMessage;
use App\Models\Conversation;
use App\Models\ConversationFolder;
use App\Models\User;
use App\Services\Ai\ActiveConversation;
use App\Services\Ai\ConversationBudget;
use App\Services\Ai\SummarisesConversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The history panel's endpoints — item 5.
 *
 * ⚠️ ONE CONTROLLER FOR BOTH SURFACES, and the surface is a ROUTE DEFAULT
 * rather than something the browser posts. Every action here is scoped by it,
 * so a surface taken from the request body would be the one field somebody
 * could change to read Breakfast's dashboard history from a client's session.
 * The route says which side it is, because the route is already behind the
 * middleware that decided that.
 *
 * ⚠️ AND THE BRAND COMES FROM THE SESSION, NEVER FROM THE REQUEST — on the
 * portal it is $user->activeBrand(), the same source the assistant itself uses.
 * There is no slug here to swap.
 *
 * Everything answers JSON: this panel is driven by fetch(), and
 * bootstrap/app.php renders framework failures as JSON for any caller that asks
 * (trap 13), so a 404 from here reaches the panel as something it can read.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ActiveConversation $conversations,
        private readonly ConversationBudget $budget,
        private readonly SummarisesConversations $summariser,
    ) {}

    /**
     * The panel's whole contents: folders, conversations, and what is archived.
     *
     * One request rather than three. The panel is drawn once on load and again
     * after anything that moves a row, and three round trips would let it show
     * a folder that no longer holds the conversation shown inside it.
     */
    public function index(Request $request): JsonResponse
    {
        [$user, $surface, $clientId] = $this->scope($request);

        return response()->json([
            'folders' => ConversationFolder::query()
                ->for($user->id, $surface, $clientId)
                ->get()
                ->map(fn (ConversationFolder $f): array => [
                    'id' => $f->id,
                    'name' => $f->name,
                ])
                ->all(),

            'conversations' => Conversation::query()
                ->for($user->id, $surface, $clientId)
                ->get()
                ->map(fn (Conversation $c): array => $this->row($c))
                ->all(),

            'archived' => Conversation::query()
                ->archived($user->id, $surface, $clientId)
                ->get()
                ->map(fn (Conversation $c): array => $this->row($c))
                ->all(),
        ]);
    }

    /** Start one, optionally inside a folder — the right-click on a folder. */
    public function store(ConversationRequest $request): JsonResponse
    {
        [$user, $surface, $clientId] = $this->scope($request);

        $folder = $this->folder($request, $user, $surface, $clientId);

        $conversation = $this->conversations->open(
            $user,
            $surface,
            $user->activeBrand(),
            $folder,
        );

        return response()->json($this->row($conversation), 201);
    }

    /**
     * One conversation's turns, for loading it into the panel.
     *
     * ⚠️ THE WHOLE THING, not replayable(). That method answers a different
     * question — what the MODEL should read — and it deliberately drops
     * everything the summary covers. A person opening a conversation is looking
     * for what was said, and the part that was folded up is usually exactly the
     * part they cannot remember.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->reachable($request, $conversation);

        return response()->json([
            ...$this->row($conversation),
            'summary' => $conversation->summary,
            'messages' => $conversation->messages()
                ->get()
                ->map(fn (AssistantMessage $m): array => [
                    'role' => $m->role,
                    'body' => $m->body,
                    'attachments' => $m->attachments ?? [],
                    'at' => $m->created_at?->translatedFormat('j M, H:i'),
                ])
                ->all(),
        ]);
    }

    /**
     * Rename, move between folders, archive or restore.
     *
     * ⚠️ ONE ENDPOINT FOR ALL FOUR because they are one thing: the row's own
     * fields. Four routes would mean four places to repeat the same ownership
     * check, and a check repeated four times is a check forgotten once.
     *
     * ⚠️ ABSENT IS NOT NULL HERE. A missing `folder` means "leave it where it
     * is"; `folder: null` means "take it out of its folder". A PATCH that
     * treated them the same would empty somebody's folders every time they
     * renamed a conversation.
     */
    public function update(ConversationRequest $request, Conversation $conversation): JsonResponse
    {
        [$user, $surface, $clientId] = $this->scope($request);
        $this->reachable($request, $conversation);

        if ($request->has('title')) {
            $title = trim((string) $request->validated('title'));

            // Never blank: an untitled row in the list is unfindable, and the
            // original title is the only thing that says what this was.
            if ($title !== '') {
                $conversation->forceFill(['title' => $title])->save();
            }
        }

        if ($request->has('folder')) {
            $conversation->forceFill([
                'folder_id' => $this->folder($request, $user, $surface, $clientId)?->id,
            ])->save();
        }

        if ($request->has('archived')) {
            $conversation->forceFill([
                'archived_at' => $request->boolean('archived') ? now() : null,
            ])->save();
        }

        return response()->json($this->row($conversation->fresh()));
    }

    /**
     * Put it in the bin.
     *
     * Soft, and that is not habit: a conversation is a record of what somebody
     * was told, and "borrar" in a side panel is one careless click away from
     * "archivar". The row survives; the person's own list stops showing it.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->reachable($request, $conversation);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * The meter's "resumir ahora".
     *
     * Somebody who knows a topic is finished should not have to wait for a
     * threshold — so this is the one caller that forces the fold.
     */
    public function summarise(Request $request, Conversation $conversation): JsonResponse
    {
        $this->reachable($request, $conversation);

        $folded = $this->summariser->handle($conversation, force: true);

        return response()->json([
            ...$this->row($conversation->fresh()),
            'folded' => $folded,
            'summary' => $conversation->fresh()->summary,
        ]);
    }

    /**
     * Correct what was folded up.
     *
     * ⚠️ THIS IS WHAT MAKES THE SUMMARY ACCEPTABLE AT ALL. It is the one place
     * in the app where model output becomes fact on a later turn without
     * anybody accepting it first (CLAUDE.md §8 rule 4) — the summariser's own
     * docblock says so, and says this is the answer: it arrives after the fact
     * instead of before it, and it is editable where it is read.
     */
    public function rewriteSummary(ConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $this->reachable($request, $conversation);

        $conversation->forceFill([
            'summary' => trim((string) $request->validated('summary')) ?: null,
        ])->save();

        return response()->json(['summary' => $conversation->summary]);
    }

    /**
     * One row as the panel draws it, including how full it is.
     *
     * @return array<string, mixed>
     */
    private function row(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            // Untitled until the first question names it — see titleFrom().
            'title' => $conversation->title ?? 'Conversación nueva',
            'folder' => $conversation->folder_id,
            'archived' => $conversation->isArchived(),
            'percent' => $this->budget->percent($conversation),
            'warn' => $this->budget->shouldWarn($conversation),
            'summarised' => $conversation->summary !== null,
            'at' => $conversation->updated_at?->translatedFormat('j M, H:i'),
        ];
    }

    /**
     * Who is asking, on which side, about which brand.
     *
     * @return array{0: User, 1: string, 2: ?int}
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $surface = (string) $request->route()->defaults['surface'];

        // ⚠️ Null on the dashboard even for a Breakfast person who has brands:
        // that conversation crosses them by design. See ActiveConversation.
        $clientId = $surface === AssistantMessage::SURFACE_PORTAL
            ? $user->activeBrand()?->id
            : null;

        return [$user, $surface, $clientId];
    }

    /** Fail closed on anything that is not this person's, here and now. */
    private function reachable(Request $request, Conversation $conversation): void
    {
        [$user, $surface, $clientId] = $this->scope($request);

        abort_unless($conversation->isReachableBy($user, $surface, $clientId), 404);
    }

    /** The folder named in the request, checked, or none. */
    private function folder(
        Request $request,
        User $user,
        string $surface,
        ?int $clientId,
    ): ?ConversationFolder {
        $id = $request->input('folder');

        if ($id === null || $id === '') {
            return null;
        }

        $folder = ConversationFolder::query()->find($id);

        // Same three questions as a conversation: whose, which side, which
        // brand. A folder from another surface would hide the conversation in a
        // list nothing on this screen draws.
        abort_if(
            $folder === null
                || ! $folder->isReachableBy($user)
                || $folder->surface !== $surface
                || $folder->client_id !== $clientId,
            404,
        );

        return $folder;
    }
}
