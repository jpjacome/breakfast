<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrandEggLayer;
use App\Enums\DeliverableItem;
use Illuminate\Contracts\View\View;

/**
 * The Brand Egg's map: which entregable feeds which layer.
 *
 * A reference screen, not a working one. It answers the question that has to be
 * settled before `EggComposer` can be written — what each of the five layers
 * reads — and it exists as a page rather than as a document because a document
 * goes stale the moment somebody edits `BrandEggLayer::sources()`.
 *
 * ⚠️ EVERYTHING HERE IS DERIVED, NOTHING IS TRANSCRIBED. The layers, their
 * sources, the labels and the obligatorio/opcional flag all come from the two
 * enums at render time. There is no list of entregables in this class or in its
 * blade, so the screen cannot disagree with the code it documents — which is
 * exactly what the stale week-one HTML in docs/ did (CLAUDE.md trap 7).
 *
 * ⚠️ NOT BRAND-SCOPED, AND NOT BEHIND THE LOGIN. It carries no {client},
 * because the mapping is the shape of the model rather than a reading of one
 * brand — no client's data reaches this page, and no user is dereferenced, so
 * it renders for a guest.
 *
 * ⚠️ UNLISTED, WHICH IS NOT THE SAME AS SECRET. Breakfast's decision of
 * 2026-09-14: the page is public so it can be sent to them for review without
 * an account, but it carries noindex/nofollow and nothing in the site links to
 * it, so it is reachable only by someone who has the URL. It does publish the
 * 48 entregables, which is Breakfast's own method — that was the decision, and
 * it is worth knowing before adding anything to this screen that a client or a
 * competitor should not read.
 */
class BrandEggMapController extends Controller
{
    /**
     * How the entregables that feed NO layer are grouped for reading.
     *
     * ⚠️ THE ONLY EDITORIAL THING ON THIS SCREEN, and it is here rather than in
     * the blade so there is one place to correct it. The groups carry no meaning
     * to the app: they exist so 38 rows can be read as five ideas instead of one
     * list, and so the visual ones — the answer to "what does «Brand Assets»
     * mean in layer 4" — can be looked at together.
     *
     * An entregable missing from this map is not lost: it falls into "Otros" in
     * unassigned(), so adding a 49th makes it appear rather than disappear.
     *
     * @var array<string, array<int, string>>
     */
    private const GROUPS = [
        'Identidad visual' => [
            'emblemas', 'contexto_simbologia', 'brand_universe',
            'identificativo_principal', 'identificativo_secundario',
            'colores', 'tipografia', 'aplicaciones', 'personaje',
            'audiologo', 'ilustraciones',
        ],
        'Voz y territorio' => [
            'tono', 'temas_conversacion', 'territorio', 'brand_x',
            'lineamientos', 'prisma_kapferer', 'arquitectura',
        ],
        'Contenido y campañas' => [
            'campana_paid', 'pilares_contenido', 'distribucion_contenido',
            'idea_evento', 'influencers', 'banco_ideas', 'perfiles_sociales',
            'referencias_contenido', 'branded_content_idea',
            'branded_content_perfil', 'branded_content_framework',
        ],
        'Service design' => [
            'service_design_awareness', 'service_design_interaccion',
            'service_design_consideracion', 'service_design_compra',
            'service_design_service', 'service_design_loyalty',
        ],
        'Análisis e implementación' => [
            'analisis_categoria', 'analisis_digital', 'checklist_implementacion',
        ],
    ];

    /** Which group the visual entregables sit in — the one layer 4 turns on. */
    private const CANDIDATE_GROUP = 'Identidad visual';

    public function index(): View
    {
        $assigned = $this->assigned();

        return view('site.brand-egg', [
            'layers' => BrandEggLayer::cases(),
            'groups' => $this->unassigned($assigned),
            'candidateGroup' => self::CANDIDATE_GROUP,
            'assignedCount' => count($assigned),
            'totalCount' => count(DeliverableItem::cases()),
        ]);
    }

    /**
     * Every entregable that feeds at least one layer, keyed by column name.
     *
     * @return array<string, true>
     */
    private function assigned(): array
    {
        $assigned = [];

        foreach (BrandEggLayer::cases() as $layer) {
            foreach ($layer->sources() as $item) {
                $assigned[$item->value] = true;
            }
        }

        return $assigned;
    }

    /**
     * The rest, in reading groups.
     *
     * Empty groups are dropped rather than printed empty: if every entregable in
     * one is eventually assigned to a layer, the group has nothing left to say.
     *
     * @param  array<string, true>  $assigned
     * @return array<string, array<int, DeliverableItem>>
     */
    private function unassigned(array $assigned): array
    {
        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        $groups['Otros'] = [];

        foreach (DeliverableItem::cases() as $item) {
            if (isset($assigned[$item->value])) {
                continue;
            }

            $groups[$this->groupFor($item)][] = $item;
        }

        return array_filter($groups);
    }

    private function groupFor(DeliverableItem $item): string
    {
        foreach (self::GROUPS as $name => $values) {
            if (in_array($item->value, $values, true)) {
                return $name;
            }
        }

        return 'Otros';
    }
}
