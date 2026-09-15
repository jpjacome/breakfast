<?php

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use App\Enums\DeliverableItem;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Creating a brand. Just the brand's own details.
 *
 * It used to carry the whole 42-field context form in the same submit. The 48
 * entregables that replaced those fields are work products rather than intake,
 * so they start empty and get written on the process screen — see
 * docs/entregables.md.
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:80'],
            'status' => ['required', new Enum(ClientStatus::class)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Which draft this form has been filling in, if any.
            'draft' => ['nullable', 'string', 'max:160'],

            // The board rides along: the assistant fills it on this screen and
            // Crear marca is the one save. Built from the enum so a new
            // entregable is never silently rejected.
            'entregables' => ['nullable', 'array'],
            ...collect(DeliverableItem::cases())
                ->mapWithKeys(fn ($item) => [
                    "entregables.{$item->value}" => ['nullable', 'string', 'max:20000'],
                ])->all(),
        ];
    }

    /**
     * The 48, trimmed, blanks stored as null.
     *
     * @return array<string, string|null>
     */
    public function deliverables(): array
    {
        $submitted = $this->validated('entregables') ?? [];
        $values = [];

        foreach (DeliverableItem::cases() as $item) {
            $value = trim((string) ($submitted[$item->value] ?? ''));
            $values[$item->value] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * The draft this form belongs to.
     *
     * Resolved through visibleTo and checked for Borrador, so a hand-posted
     * slug cannot turn somebody else's live brand into a fresh one.
     */
    public function existingDraft(): ?Client
    {
        $slug = trim((string) $this->validated('draft'));

        if ($slug === '') {
            return null;
        }

        return Client::query()
            ->visibleTo($this->user())
            ->where('slug', $slug)
            ->where('status', ClientStatus::Borrador)
            ->first();
    }

    /** The status the form asked for, never Borrador. */
    public function chosenStatus(): ClientStatus
    {
        $status = ClientStatus::tryFrom((string) $this->validated('status'));

        return $status === null || $status->isDraft()
            ? ClientStatus::Activo
            : $status;
    }

    /** The brand's own columns. */
    public function clientAttributes(): array
    {
        return $this->safe()->only([
            'name', 'industry', 'status', 'contact_name', 'contact_email', 'notes',
        ]);
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre de la marca',
            'industry' => 'industria',
            'status' => 'estado',
            'contact_name' => 'nombre de contacto',
            'contact_email' => 'correo de contacto',
            'notes' => 'notas',
        ];
    }
}
