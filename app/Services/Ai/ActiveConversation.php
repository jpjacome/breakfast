<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationFolder;
use App\Models\User;

/**
 * Which conversation a turn belongs to — item 5.
 *
 * THE ONE CLASS THAT DECIDES IT. Both assistant endpoints and the whole side
 * panel ask here; nothing else resolves a conversation for itself. Two places
 * deciding is two chances for a question to land in a thread the panel is not
 * showing, which reads as the answer having vanished.
 *
 * ⚠️ IT FAILS CLOSED, AND THAT IS THE POINT OF IT EXISTING. A conversation id
 * arrives from the browser on every turn, so it is the one piece of this
 * feature somebody could hand-post. An id that is not this person's, not this
 * surface's or not this brand's is a 404 — never "ignore it and use the
 * default", which would quietly write one person's question into the thread
 * they aimed at.
 *
 * ⚠️ ADMIN AND PORTAL SCOPE DIFFERENTLY, and the difference is deliberate. The
 * dashboard's conversation crosses brands by design — "compará Alea con la
 * otra" is the question it exists to answer — so it is stored with a null
 * `client_id` and asked for without one. Brandy's is per brand, or switching
 * brands would show one brand's titles while looking at another (ACC-01).
 *
 * ⚠️ THE EGG IS NOT HERE. `brand_egg_messages` is keyed on the BRAND, not on a
 * person, because building an Egg is team work — and `Conversation` is a
 * person's own, down to `scopeFor()`. Giving it conversations means answering
 * that question first, which is not this pass's.
 *
 * @see docs/implementaciones.md — item 5
 */
final class ActiveConversation
{
    /**
     * The conversation this turn belongs to, resolving or creating one.
     *
     * `$requested` is what the panel posted. Null means "wherever I left off",
     * which is what a page opened without touching the panel means — and what
     * every turn meant before the panel existed.
     */
    public function for(
        User $user,
        string $surface,
        ?Client $client = null,
        ?int $requested = null,
    ): Conversation {
        if ($requested !== null) {
            $conversation = Conversation::query()->find($requested);

            // Fail closed: an id that is not theirs is a 404, never a fallback.
            abort_if(
                $conversation === null
                    || ! $conversation->isReachableBy($user, $surface, $client?->id),
                404,
            );

            return $conversation;
        }

        // ⚠️ THE NEWEST OPEN ONE, NOT THE NEWEST. An archived conversation is
        // somebody saying they are done with it; continuing it because it
        // happens to be the most recent undoes the only thing archiving does.
        return Conversation::query()
            ->for($user->id, $surface, $client?->id)
            ->first()
            ?? $this->open($user, $surface, $client);
    }

    /**
     * Start a new one.
     *
     * Untitled on purpose — `titleFrom()` names it from the first question,
     * because nobody titles a chat before having it.
     */
    public function open(
        User $user,
        string $surface,
        ?Client $client = null,
        ?ConversationFolder $folder = null,
    ): Conversation {
        return Conversation::create([
            'user_id' => $user->id,
            'surface' => $surface,
            // Null on the dashboard, always. See the class note.
            'client_id' => $surface === AssistantMessage::SURFACE_PORTAL ? $client?->id : null,
            'folder_id' => $folder?->id,
        ]);
    }
}
