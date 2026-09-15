<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\StartBrandDraft;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientDraftRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

/**
 * Autosave for /clientes/nueva.
 *
 * The screen posts here when something has changed and five minutes have
 * passed. The first post that carries content is what brings the draft row
 * into existence — see StartBrandDraft, which is the only place that happens.
 *
 * It answers with the draft's slug, which the page then holds: every later
 * save and every assistant turn on that screen names it, so a reload or a
 * second tab cannot fork the same brand into two rows.
 */
class ClientDraftController extends Controller
{
    public function __construct(private readonly StartBrandDraft $drafts) {}

    public function save(SaveClientDraftRequest $request): JsonResponse
    {
        $deliverables = $request->deliverables();

        // A filled entregable is content, even with the brand form still blank.
        // Approving a batch of proposals before typing a name is the normal
        // opening move on this screen, and it has to bring the draft into
        // being — otherwise those approvals have nowhere to be saved.
        $written = $deliverables === null
            ? false
            : array_filter($deliverables) !== [];

        $draft = $this->drafts->resolve(
            $request->user(),
            $this->existingDraft($request),
            $request->draftAttributes(),
            force: $written,
        );

        // Nothing written yet is not an error: the page saves on a timer and
        // most of those ticks have nothing to say.
        if ($draft === null) {
            return response()->json(['draft' => null, 'saved' => false]);
        }

        if ($deliverables !== null) {
            $draft->deliverables()->firstOrNew()->fill([
                ...$deliverables,
                'updated_by' => $request->user()->id,
            ])->save();
        }

        return response()->json([
            'draft' => $draft->slug,
            'name' => $draft->name,
            'saved' => true,
            'at' => $draft->updated_at?->format('H:i'),
        ]);
    }

    /**
     * The draft this page is already working on, if it named one.
     *
     * Resolved through visibleTo and checked for Borrador: a hand-posted slug
     * must not let anyone autosave over a live brand.
     */
    private function existingDraft(SaveClientDraftRequest $request): ?Client
    {
        $slug = trim((string) $request->validated('draft'));

        if ($slug === '') {
            return null;
        }

        return Client::query()
            ->visibleTo($request->user())
            ->where('slug', $slug)
            ->where('status', ClientStatus::Borrador)
            ->first();
    }
}
