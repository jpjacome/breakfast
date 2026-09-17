<?php

namespace App\Models;

use App\Enums\BrandEggLayer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A brand's Brand Egg — five synthesised layers, one row.
 *
 * Singular where BrandDeliverables is plural, and the difference is real: the
 * entregables row is "all of them", a flat set of 48 peers. The Egg is one
 * object with five named parts arranged around a core, and it is asked about
 * as a whole — composed, approved, out of date. The states in BrandEggState
 * belong to the egg, never to a layer.
 *
 * ⚠️ THE EGG SITS ABOVE THE ENTREGABLES IN THE ASSISTANT'S CONTEXT, and that
 * is the only reason it exists. It is not a summary screen. It is the brand's
 * primary memory: the reviewed detail is beneath it, the toolkit beneath that
 * (CLAUDE.md §7). So everything here has to hold the same guarantee the
 * entregables hold — every word traceable to something a person accepted.
 *
 * ⚠️ WHICH IS WHY THERE IS NO WRITE PATH FROM THE MODEL HERE. EggComposer
 * returns text a person accepts; nothing in this class is written by a
 * generation run on its own. Same rule as CLAUDE.md §8 rule 4 — no provenance
 * column, because there is no state where the model authored a value alone.
 */
class BrandEgg extends Model
{
    /**
     * The five columns plus the two acts and who performed the second.
     *
     * Generated from the enum so a sixth layer never has to be remembered in
     * two places — the same argument as BrandDeliverables::getFillable(), and
     * a METHOD for the same reason as well: a property initialiser is a
     * constant expression and cannot call columns().
     *
     * ⚠️ generated_at IS FILLABLE AND approved_at IS NOT. Composing writes the
     * layer and its stamp in one save, so they travel together and cannot
     * disagree. Approving is a person's act on a whole egg: it goes through
     * approve() alone, where approved_by is written in the same breath, so
     * there is no path that stamps an approval with nobody attached to it.
     */
    public function getFillable(): array
    {
        return [...BrandEggLayer::columns(), 'generated_at'];
    }

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Who approved it. Nulled rather than cascaded when that person leaves. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The files that make up the inventory layer, in the order somebody chose.
     *
     * ⚠️ REFERENCES, NEVER COPIES. The Egg stores row ids; the title, the type,
     * the description and the URL are read from `brand_assets` every time. So
     * correcting a file's description corrects the Egg with nothing to re-run,
     * replacing a logo replaces what the Egg points at, and deleting a file
     * removes it rather than leaving prose describing something that is gone.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(BrandAsset::class, 'brand_egg_assets')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('brand_egg_assets.position');
    }

    /**
     * ⚠️ EMPTY FOR THE INVENTORY LAYER, ALWAYS. It has no column and no text —
     * asking for its words is a category error, and returning '' rather than
     * failing keeps every caller that loops the five layers honest.
     */
    public function text(BrandEggLayer $layer): string
    {
        if ($layer->isInventory()) {
            return '';
        }

        return trim((string) ($this->{$layer->value} ?? ''));
    }

    /**
     * Whether this layer has anything in it.
     *
     * ⚠️ TWO DIFFERENT QUESTIONS BEHIND ONE NAME. For four layers it is "has
     * text"; for the inventory it is "has any file". Both are "is this ring
     * filled in", which is what every caller actually wants — the drawing, the
     * state, the screen.
     */
    public function has(BrandEggLayer $layer): bool
    {
        if ($layer->isInventory()) {
            return $this->exists && $this->assets()->exists();
        }

        return $this->text($layer) !== '';
    }

    /** No layer has any text. The state every brand starts in. */
    public function isEmpty(): bool
    {
        foreach (BrandEggLayer::cases() as $layer) {
            if ($this->has($layer)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The layers that have text, in ring order.
     *
     * @return array<int, BrandEggLayer>
     */
    public function composed(): array
    {
        return array_values(array_filter(
            BrandEggLayer::cases(),
            fn (BrandEggLayer $layer) => $this->has($layer),
        ));
    }

    /**
     * Layer value => its text, for <x-brand-egg :egg="...">.
     *
     * The component takes a plain array rather than this model so that the
     * bench screen and the client's read-only egg can hand it the same shape
     * without either of them needing a row.
     *
     * ⚠️ NOT NAMED toArray(). That name is Eloquent's own serialisation hook —
     * overriding it would change what this model becomes in every ->toJson(),
     * every collection cast and every response that ever returns one, to make
     * one Blade component's prop slightly shorter. This is a view shape, so it
     * says so.
     *
     * @return array<string, ?string>
     */
    public function layerTexts(): array
    {
        $out = [];

        foreach (BrandEggLayer::cases() as $layer) {
            // The drawing only asks "is this ring filled", so the inventory
            // answers with its own count rather than with words it does not
            // have. A ring is hollow or it is not.
            $out[$layer->value] = match (true) {
                ! $this->has($layer) => null,
                $layer->isInventory() => $this->assets->count().' archivos',
                default => $this->text($layer),
            };
        }

        return $out;
    }

    /**
     * The Egg as the markdown block the assistant reads, above the entregables.
     *
     * ⚠️ AN UNCOMPOSED LAYER IS OMITTED, NOT PRINTED AS NO DEFINIDO — the
     * opposite of BrandDeliverables::toMarkdown(), and the difference is not an
     * inconsistency. There, silence about an entregable is a hole the model
     * fills with whatever is most probable for the category, so absence has to
     * be said out loud. Here, the layer BENEATH is still in the prompt: an
     * uncomposed Esencia does not leave the model guessing at the brand's
     * essence, it leaves it reading the Relato and the Brand promise directly,
     * which is the honest answer and one tier lower exactly as intended.
     * Announcing five missing syntheses would only invite the model to write
     * them itself.
     *
     * MUST STAY BYTE-STABLE for a given row: this goes into block 2, whose
     * exact bytes are the caching mechanism (CLAUDE.md §7). No timestamps —
     * the approval LINE is assembled by BrandContextRepository, which owns the
     * decision about what the prompt says, and it is stable per brand too.
     */
    public function toMarkdown(): string
    {
        $lines = [];

        foreach ($this->composed() as $layer) {
            $lines[] = "**{$layer->label()}**\n"
                .($layer->isInventory() ? $this->inventoryMarkdown() : $this->text($layer));
        }

        return implode("\n\n", $lines);
    }

    /**
     * The inventory layer, rendered from the rows it points at.
     *
     * ⚠️ READ AT RENDER TIME, which is the whole reason this layer holds ids
     * rather than words. The type says what a file IS — a description can tell
     * you a mark is a heavy circular stamp, never that it is THE primary one —
     * and the URL is what lets "muéstrame el logo" be answered with the file.
     *
     * ⚠️ Still byte-stable for a given set of rows: no timestamps, no counts,
     * nothing that moves between requests. This lands in block 2, whose exact
     * bytes are the caching mechanism (CLAUDE.md §7).
     */
    private function inventoryMarkdown(): string
    {
        return $this->assets
            ->map(function (BrandAsset $asset): string {
                $what = $asset->type?->label() ?? 'Sin tipo';
                $reading = trim((string) $asset->visual_reading);

                return "- {$what} · {$asset->title} — {$asset->url()}"
                    .($reading === '' ? '' : "\n  {$reading}");
            })
            ->implode("\n");
    }
}
