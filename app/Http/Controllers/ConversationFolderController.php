<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConversationFolderRequest;
use App\Models\AssistantMessage;
use App\Models\ConversationFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The folders somebody sorts their own conversations into — item 5.
 *
 * Scoped exactly like ConversationController and for the same reasons: the
 * surface is a route default, the brand comes from the session, and anything
 * that is not this person's is a 404.
 *
 * ⚠️ DELETING A FOLDER NEVER DELETES WHAT IS IN IT. `conversations.folder_id`
 * is nullOnDelete, so they fall back to the unfiled list — losing a month of
 * work by tidying up is the most expensive mistake this panel could allow, and
 * it is one careless click away from an ordinary one.
 */
class ConversationFolderController extends Controller
{
    public function store(ConversationFolderRequest $request): JsonResponse
    {
        [$user, $surface, $clientId] = $this->scope($request);

        $folder = ConversationFolder::create([
            'user_id' => $user->id,
            'surface' => $surface,
            'client_id' => $clientId,
            'name' => trim((string) $request->validated('name')),
            // At the end of their own list. Alphabetical is not what anybody
            // means by "my folders" — see the model.
            'position' => (int) ConversationFolder::query()
                ->for($user->id, $surface, $clientId)
                ->max('position') + 1,
        ]);

        return response()->json(['id' => $folder->id, 'name' => $folder->name], 201);
    }

    /** Rename it, or move it in the list. */
    public function update(ConversationFolderRequest $request, ConversationFolder $folder): JsonResponse
    {
        $this->reachable($request, $folder);

        if ($request->has('name')) {
            $name = trim((string) $request->validated('name'));

            // Never blank: a folder with no name is a row nobody can aim at.
            if ($name !== '') {
                $folder->forceFill(['name' => $name])->save();
            }
        }

        if ($request->has('position')) {
            $folder->forceFill(['position' => (int) $request->validated('position')])->save();
        }

        return response()->json(['id' => $folder->id, 'name' => $folder->name]);
    }

    public function destroy(Request $request, ConversationFolder $folder): JsonResponse
    {
        $this->reachable($request, $folder);

        // Hard, unlike a conversation — a folder holds nothing of its own. What
        // was in it survives, unfiled, because the foreign key says so.
        $folder->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{0: User, 1: string, 2: ?int}
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $surface = (string) $request->route()->defaults['surface'];

        $clientId = $surface === AssistantMessage::SURFACE_PORTAL
            ? $user->activeBrand()?->id
            : null;

        return [$user, $surface, $clientId];
    }

    private function reachable(Request $request, ConversationFolder $folder): void
    {
        [$user, $surface, $clientId] = $this->scope($request);

        abort_unless(
            $folder->isReachableBy($user)
                && $folder->surface === $surface
                && $folder->client_id === $clientId,
            404,
        );
    }
}
