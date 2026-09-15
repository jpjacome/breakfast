<?php

namespace App\Models;

use App\Enums\DeliverableItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's 48 entregables — and the assistant's whole database.
 *
 * Plural on purpose: the row is not one deliverable, it is all of them. It is
 * what BrandProfile was, with 48 real columns instead of a JSON blob and the
 * 42-field vocabulary swapped for the 48-entregable one.
 *
 * Three rules carry the safety model, and all three are about what the model
 * is allowed to assume:
 *
 *   · An entregable is ALWAYS text. When the entregable IS an asset — the
 *     identificativo, the ilustraciones — the text is the link to the file,
 *     and several files mean several links. So there is no row the model
 *     cannot read.
 *   · There is no status column. Filled is done, empty is pending. A separate
 *     status would only add a second truth that someone has to remember to
 *     move, and that can contradict the content.
 *   · Absence is stated, never omitted. See toMarkdown().
 */
class BrandDeliverables extends Model
{
    /** Laravel would guess brand_deliverable from the plural class name. */
    protected $table = 'brand_deliverables';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The 48 columns plus the editor. Generated from the enum so a new
     * entregable never has to be remembered in two places.
     */
    public function getFillable(): array
    {
        return [...DeliverableItem::columns(), 'updated_by'];
    }

    public function value(DeliverableItem $item): string
    {
        return trim((string) ($this->{$item->value} ?? ''));
    }

    public function has(DeliverableItem $item): bool
    {
        return $this->value($item) !== '';
    }

    /**
     * Required entregables with nothing in them.
     *
     * @return array<int, DeliverableItem>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            DeliverableItem::required(),
            fn (DeliverableItem $item) => ! $this->has($item),
        ));
    }

    /** How many of the 48 have content, required or not. */
    public function filledCount(): int
    {
        return count(array_filter(
            DeliverableItem::cases(),
            fn (DeliverableItem $item) => $this->has($item),
        ));
    }

    /**
     * How much of the required set is defined, 0–100.
     *
     * Optionals are deliberately excluded: counting them would mean no brand
     * is ever finished, and a score that can never reach 100 is not a score.
     *
     * Nothing gates on this. It is shown to the admin and given to the
     * assistant so it can answer "how much of our brand is missing"; it does
     * not decide when a step closes.
     */
    public function completeness(): int
    {
        $total = count(DeliverableItem::required());

        if ($total === 0) {
            return 100;
        }

        return (int) round((($total - count($this->missing())) / $total) * 100);
    }

    /**
     * The 48 as the markdown block the assistant reads.
     *
     * Two rules decide what appears here, and both exist to stop the model
     * filling a hole it cannot see:
     *
     *   · A required entregable with no content is printed as NO DEFINIDO.
     *     Omitting it would leave silence, and silence gets completed with
     *     whatever is most probable for the category.
     *   · Empty optionals are collected into one closing list instead of
     *     twenty-eight NO DEFINIDO lines. Still an explicit statement of
     *     absence, without drowning the entregables that exist.
     *
     * MUST STAY BYTE-STABLE for a given row: this goes into block 2 of the
     * prompt, whose exact bytes are the whole caching mechanism on DeepSeek.
     * No timestamps, no user names, no "hoy es". See BrandContextBuilder.
     */
    public function toMarkdown(): string
    {
        $lines = [];
        $undefined = [];

        foreach (DeliverableItem::cases() as $item) {
            if ($this->has($item)) {
                $lines[] = "**{$item->label()}**\n{$this->value($item)}";

                continue;
            }

            if ($item->isRequired()) {
                $lines[] = "**{$item->label()}**\nNO DEFINIDO";
            } else {
                $undefined[] = $item->label();
            }
        }

        if ($undefined !== []) {
            // ⚠️ THE INSTRUCTION MATTERS AS MUCH AS THE LIST. This exists so the
            // model does not invent what is missing — not so it can recite it.
            // Until 2026-08-23 it said "si te preguntan por alguno, dilo y
            // ofrece ayudar a definirlo", and Brandy read that as licence to
            // volunteer: a client asking about their campaign was told "nos
            // falta definir la tipografía oficial", which reads as Breakfast
            // announcing its own unfinished homework. The absence is internal
            // working material; the list stays, the invitation goes.
            $lines[] = "**No definido en este perfil**\nLa marca no tiene definido, a día de hoy: "
                .implode(', ', $undefined).'. Esto es información interna de Breakfast: está aquí '
                .'para que no inventes estos datos, NO para contársela al cliente. No la enumeres '
                .'ni la menciones por tu cuenta. Si te preguntan directamente por alguno, di que '
                .'todavía no está definido y sigue adelante, sin presentarlo como un pendiente.';
        }

        return implode("\n\n", $lines);
    }
}
