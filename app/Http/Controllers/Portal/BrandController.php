<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\DeliverableItem;
use App\Enums\PortalSection;
use App\Http\Controllers\Controller;
use App\Services\Checklist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The brand, as it has been written so far.
 *
 * The page writes itself: every entregable with content appears, in board
 * order, and nothing else does. It starts empty on a new brand and grows as
 * the work lands — the brandbook assembling in public.
 *
 * ONLY WHAT IS WRITTEN. An empty entregable is absent, not shown as a gap.
 * That is the opposite of what the assistant reads, where an empty required
 * one is stated as NO DEFINIDO so the model cannot fill a hole it cannot see —
 * but a model needs to know what is missing and a client does not need a list
 * of what they have not been given yet.
 */
class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $deliverables = $request->user()->activeBrand()->deliverablesOrNew();

        $written = array_values(array_filter(
            DeliverableItem::cases(),
            // The checklist gets its own tickable section below the entregables,
            // so printing it again as a block of text would be the same content
            // twice — once actionable and once not.
            fn (DeliverableItem $item) => $deliverables->has($item)
                && $item !== DeliverableItem::ChecklistImplementacion,
        ));

        $client = $request->user()->activeBrand();

        return view('portal.estrategia', [
            'section' => PortalSection::Estrategia,
            'client' => $client,
            'deliverables' => $deliverables,
            'written' => $written,
            'checklist' => Checklist::for($deliverables),
            'ticks' => $client->checklistTicks()->with('checkedBy')->get()->keyBy('item_key'),
        ]);
    }
}
