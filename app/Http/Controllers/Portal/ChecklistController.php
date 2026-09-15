<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ChecklistTick;
use App\Services\Checklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client ticking an item off their implementation checklist — SEG-05.
 *
 * ⚠️ THE ONLY WRITE PATH ON THE CLIENT SIDE OF THE PORTAL BESIDES PERFIL, and
 * deliberately the smallest one that could exist. CLAUDE.md §5 and §11 said the
 * client writes nothing at all — Breakfast writes a brand, the brand reads it —
 * and this is the stated exception, narrowed to the point where it cannot grow
 * into something else:
 *
 *   · it writes to checklist_ticks and nothing else. Never brand_deliverables.
 *   · the only thing it can say about an item is done or not done.
 *   · the item must ALREADY EXIST in the brand's own checklist text. A key that
 *     is not in it is refused, so nobody can invent items by posting keys.
 *
 * The brand comes from the user, never from the request — the same rule as
 * Portal\AssistantController. There is no brand parameter to swap.
 *
 * A form post answering with back(), not a fetch(): a redirect is the honest
 * answer here, it keeps working with JavaScript off, and it sidesteps trap 13
 * entirely (bootstrap/app.php renders validation as a redirect outside api/*,
 * which a fetch would read as a silent failure).
 */
class ChecklistController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $client = $user->activeBrand();

        // Breakfast staff can open /portal and carry client_id = null
        // (CLAUDE.md §11). Fail closed rather than dereference it.
        abort_if($client === null, 404);

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*' => ['string', 'size:40'],
        ], [], ['items' => 'ítems']);

        // ⚠️ WHAT THE BRAND'S OWN CHECKLIST ACTUALLY SAYS is the whitelist. The
        // posted keys are compared against it, so a hand-crafted key ticks
        // nothing: the client may answer the questions Breakfast asked, not add
        // questions of their own.
        $known = Checklist::for($client->deliverablesOrNew())->keys();

        $ticked = array_values(array_intersect(
            array_unique((array) ($validated['items'] ?? [])),
            $known,
        ));

        // The whole list is submitted every time and replaced here, so
        // unticking is simply a key not coming back. Scoped to the items that
        // are currently IN the checklist: a tick against a line Breakfast has
        // since deleted is left alone rather than quietly destroyed, so
        // restoring the line restores its tick.
        ChecklistTick::query()
            ->where('client_id', $client->id)
            ->whereIn('item_key', $known)
            ->delete();

        $now = now();

        ChecklistTick::query()->insertOrIgnore(array_map(
            static fn (string $key): array => [
                'client_id' => $client->id,
                'item_key' => $key,
                'checked_by' => $user->id,
                'checked_at' => $now,
            ],
            $ticked,
        ));

        return back()->with('status', 'Checklist actualizado.');
    }
}
