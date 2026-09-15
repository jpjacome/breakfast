<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BrandEggLayer;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeBrandEggRequest;
use App\Models\Client;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\BrandEgg\EggComposer;
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
    public function __construct(private readonly EggComposer $composer) {}

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
