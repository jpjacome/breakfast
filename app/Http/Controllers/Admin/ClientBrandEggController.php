<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeBrandEggRequest;
use App\Http\Requests\EggAssistantRequest;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\BrandEgg\EggAssistant;
use App\Services\BrandEgg\EggComposer;
use App\Services\BrandEgg\LayerProgress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Brand Egg — the admin side of it.
 *
 * Five synthesised layers sitting ABOVE the 48 entregables in the assistant's
 * context. See docs/brand-egg.md: the Egg is composed from the ENTREGABLES,
 * never straight from the toolkit, because going straight from the PDF would
 * skip the one review a person actually performs and put an unreviewed reading
 * at the top of the brand's memory.
 *
 * ⚠️ IT IS A BRAND-SCOPED ROUTE GROUP. Every action carries {client}, so the
 * 'covers-client' middleware on the /admin group guards all four with nothing
 * to remember — an equipo user not assigned to this brand gets a 404, not a
 * 403, on the compose route as readily as on the screen (CLAUDE.md §6).
 *
 * ⚠️ compose() ANSWERS JSON AND THE OTHER TWO WRITES ANSWER back(), and that is
 * not an inconsistency. Composing is an AI call of 20 to 100 seconds driven by
 * fetch() so the screen can say which ring it is on; accepting an edited layer
 * and approving an Egg are ordinary admin writes that should return you to the
 * screen you clicked from, like every other write in /admin.
 */
class ClientBrandEggController extends Controller
{
    public function __construct(
        private readonly EggComposer $composer,
        private readonly EggAssistant $assistant,
    ) {}

    /**
     * One turn of the conversation that co-creates the Egg — §1 of the brief.
     *
     * ⚠️ JSON, like compose(), and for the same reason: a fetch() drives the
     * panel so the screen can keep the checklist and the thread in step
     * without a reload.
     *
     * ⚠️ IT ANSWERS WITH THE CHECKLIST AS WELL AS THE REPLY. The whole point of
     * the flow is that accepting a card moves a tick, and the tick is derived
     * from `brand_deliverables` — so the screen has to be handed the new
     * reading rather than guessing at it from what she said.
     */
    public function assistant(EggAssistantRequest $request, Client $client): JsonResponse
    {
        $layer = $request->layer();

        try {
            $answer = $this->assistant->answer(
                $client,
                $request->user(),
                (string) $request->validated('message'),
                $layer,
            );
        } catch (LlmException $e) {
            // What a person may be told when a turn fails — never the
            // provider's own words. forStaff: this panel is /admin, and the
            // Breakfast team can be told more than a client can.
            return response()->json(['error' => AssistantFailure::message($e, forStaff: true)], 502);
        }

        return response()->json([
            'reply' => $answer['reply'],
            'layer' => $layer?->value,
            'checklist' => $layer === null || $layer->isInventory()
                ? null
                : LayerProgress::for($client->fresh(), $layer)->toMarkdown(),
        ]);
    }

    /**
     * Save ONE entregable of ONE section — what a play-back card accepts into.
     *
     * ⚠️ THE SECTION IS IN THE URL AND IT IS A GATE, NOT CONTEXT. Breakfast's
     * rule, 2026-09-17: this assistant may only ever write the entregables
     * ATTACHED TO THE SECTION being worked on. Filling the yolk reaches six of
     * the 48; filling Personalidad reaches two. Everything else 404s.
     *
     * The prompt says the same thing — and a prompt is a request. This is the
     * part that makes it a fact: a loosened prompt, a confused turn or a
     * hand-made request still cannot land a card on an entregable this section
     * does not read.
     *
     * ⚠️ IT CANNOT POST TO THE BOARD'S ROUTE, and this is the trap worth
     * knowing before somebody tries. ClientProcessController@update fills from
     * UpdateDeliverablesRequest::deliverables(), which returns ALL 48 because
     * that is a full save of a form — an entregable the request does not
     * mention is one somebody emptied. One entregable through that endpoint
     * blanks the other 47: silent data loss, no error, triggered by accepting
     * a suggestion.
     *
     * ⚠️ BOTH SEGMENTS BIND TO ENUMS, so neither a section nor a column name
     * ever arrives as a string from a request body. The vocabulary is the
     * whitelist.
     *
     * ⚠️ IT WRITES THE TEXT IT IS GIVEN. It does not call the assistant,
     * re-generate or re-phrase: the card carries what the person read and
     * accepted, and anything else would break the guarantee the whole app
     * rests on.
     */
    public function deliverable(
        Request $request,
        Client $client,
        BrandEggLayer $layer,
        DeliverableItem $item,
    ): JsonResponse {
        // Fail closed. 404 rather than 422: an entregable this section does not
        // read is not a bad value, it is a route that does not exist here.
        abort_unless(in_array($item, $layer->sources(), true), 404);

        $text = trim((string) $request->input('texto'));

        $client->deliverables()->firstOrNew()->fill([
            $item->value => $text === '' ? null : $text,
            'updated_by' => $request->user()->id,
        ])->save();

        return response()->json([
            'saved' => $item->value,
            // The card moved a tick, and the tick is derived — so the panel is
            // handed the new reading rather than inferring one.
            'checklist' => LayerProgress::for($client->fresh(), $layer)->toMarkdown(),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.brand-egg', [
            'client' => $client,
            'egg' => $client->brandEggOrNew(),
            'state' => $client->brandEggState(),
            'layers' => BrandEggLayer::cases(),
            'deliverables' => $client->deliverablesOrNew(),
        ]);
    }

    /**
     * A person accepting or rewriting one layer.
     *
     * ⚠️ THE ONLY WAY TEXT REACHES A LAYER OTHER THAN A COMPOSITION, and it is
     * a person typing. There is deliberately no bulk edit: the Egg is five
     * paragraphs that are read one at a time and signed off as a whole.
     *
     * An emptied layer is a real instruction — it is how a ring is put back to
     * hollow when its synthesis was wrong and its sources are not ready — so a
     * blank is stored as null rather than refused.
     */
    public function update(Request $request, Client $client, BrandEggLayer $layer): RedirectResponse
    {
        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:5000'],
        ]);

        $text = trim((string) ($validated['text'] ?? ''));

        $egg = $client->brandEggOrNew();
        $egg->fill([$layer->value => $text === '' ? null : $text]);

        // A layer typed by hand on a brand nobody has composed still counts as
        // generated: something is in the Egg, so "sin generar" would be a lie
        // and the state would skip straight past "sin aprobar".
        if ($egg->generated_at === null && $text !== '') {
            $egg->fill(['generated_at' => now()]);
        }

        $client->brandEgg()->save($egg);

        return back()->with('status', $text === ''
            ? "Capa «{$layer->label()}» vaciada."
            : "Capa «{$layer->label()}» guardada.");
    }

    /**
     * Compose one ring, or all five in dependency order.
     *
     * ⚠️ BOTH GATES, AND THEY DO DIFFERENT JOBS (CLAUDE.md trap 5). throttle
     * counts requests per minute because every call is paid; 'ai-turn' bounds
     * how many run AT ONCE, which is the thing that takes the public site down.
     * One click here can hold a PHP worker for a hundred seconds and the pool
     * is shared with the marketing site.
     *
     * A provider failure is answered, not thrown: the layers that landed are
     * already saved, and the screen needs to say which ones so somebody can
     * finish the rest ring by ring.
     */
    public function compose(ComposeBrandEggRequest $request, Client $client): JsonResponse
    {
        $layer = $request->layer();
        $userId = $request->user()->id;

        try {
            $result = $layer !== null
                ? $this->composeOne($client, $layer, $userId)
                : $this->composer->composeAll($client, $userId);
        } catch (LlmException $e) {
            return response()->json([
                // forStaff: this screen is /admin, and an admin can be told the
                // provider is down in so many words.
                'error' => AssistantFailure::message($e, forStaff: true),
                'egg' => $client->fresh()->brandEggOrNew()->layerTexts(),
            ], 502);
        }

        $client = $client->fresh();

        return response()->json([
            ...$result,
            'egg' => $client->brandEggOrNew()->layerTexts(),
            'state' => $client->brandEggState()->value,
            'stateLabel' => $client->brandEggState()->label(),
        ]);
    }

    /**
     * @return array{composed: array<int, string>, skipped: array<int, string>, stopped: bool}
     */
    private function composeOne(Client $client, BrandEggLayer $layer, int $userId): array
    {
        $text = $this->composer->compose($client, $layer, $userId);

        return [
            'composed' => $text === null ? [] : [$layer->value],
            // Skipped here means one thing only: not one of the entregables
            // this layer reads has been written, so there was nothing to
            // synthesise and no call was made.
            'skipped' => $text === null ? [$layer->value] : [],
            'stopped' => false,
        ];
    }

    /**
     * Put a file into the Egg's inventory, or take it out again.
     *
     * ⚠️ THE EGG STORES THE ROW, NOT THE FILE'S DETAILS. Its description, its
     * type and its URL are read from `brand_assets` whenever something asks —
     * so correcting a description corrects the Egg with nothing to re-run, and
     * deleting a file removes it from the Egg rather than leaving a sentence
     * about something that no longer exists.
     *
     * ⚠️ THE ASSET MUST BELONG TO THIS BRAND. Without that check an id from
     * another brand could be attached by hand, and the Egg is the one place in
     * the app whose whole claim is that a person approved what is in it.
     */
    public function asset(Request $request, Client $client, BrandAsset $asset): RedirectResponse
    {
        // Fail closed, and 404 rather than 403: a wrong id must not confirm
        // that somebody else's file exists (CLAUDE.md §6).
        abort_unless($asset->client_id === $client->id, 404);

        $egg = $client->brandEggOrNew();

        if (! $egg->exists) {
            $client->brandEgg()->save($egg);
        }

        if ($egg->assets()->whereKey($asset->getKey())->exists()) {
            $egg->assets()->detach($asset->getKey());

            return back()->with('status', "«{$asset->title}» ya no está en el Brand Egg.");
        }

        // Appended, not sorted: the order is somebody's choice and new files
        // join the end of it rather than jumping the queue.
        $egg->assets()->attach($asset->getKey(), [
            'position' => (int) $egg->assets()->max('position') + 1,
        ]);

        return back()->with('status', "«{$asset->title}» añadido al Brand Egg.");
    }

    /**
     * Breakfast signing off the Egg.
     *
     * ⚠️ THERE IS NO UNAPPROVE, deliberately. Approving again re-stamps, which
     * is what somebody means when they approve a brand they have just edited;
     * an unapprove would be a way to take a brand's memory away from it
     * mid-project with one misclick. A wrong approval is corrected by fixing
     * the layer and approving the fix.
     */
    public function approve(Request $request, Client $client): RedirectResponse
    {
        $egg = $client->brandEggOrNew();

        if ($egg->isEmpty()) {
            // Fail closed rather than stamping an approval onto nothing: an
            // approved empty Egg would show the client a blank drawing and
            // claim a person had signed it off.
            return back()->with('status', 'No hay nada que aprobar todavía: el Brand Egg está vacío.');
        }

        $egg->forceFill([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        $client->brandEgg()->save($egg);

        return back()->with('status', 'Brand Egg aprobado. La marca ya puede verlo en su portal.');
    }
}
