<?php

namespace App\Actions;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;

/**
 * Resolve the draft brand behind /clientes/nueva, creating it on first write.
 *
 * THE ONLY CLASS THAT CREATES A DRAFT. The new-brand screen has no row until
 * somebody writes something — a name, an entregable, a file. At that moment one
 * appears, because everything the assistant does next (store a file, fill an
 * entregable, keep a thread) already works off a client_id, and holding those
 * in the session instead would be a second implementation of all three that
 * exists only until the Crear button.
 *
 * Nothing is created by merely opening the screen or by saying hello to the
 * assistant. Abandoned drafts are a real cost — they sit in the brand list —
 * so the trigger is content, not attention.
 */
class StartBrandDraft
{
    /** What a draft is called before anybody has said what it is. */
    public const PLACEHOLDER_NAME = 'Marca sin nombre';

    /**
     * The brand's own columns, apart from the name.
     *
     * The name is handled on its own everywhere here because it carries the
     * slug and the placeholder with it; these four are just values.
     */
    private const COLUMNS = ['industry', 'contact_name', 'contact_email', 'notes'];

    /**
     * The draft for this screen, or null when there is nothing to save yet.
     *
     * @param  array<string, mixed>  $attributes  The brand form as it stands.
     * @param  bool  $force  True when the caller knows there is content to
     *                       attach — an uploaded file, an entregable — even
     *                       though the brand form itself is still blank.
     */
    public function resolve(
        User $author,
        ?Client $draft,
        array $attributes = [],
        bool $force = false,
    ): ?Client {
        $name = trim((string) ($attributes['name'] ?? ''));
        $hasContent = $force || $name !== '' || $this->anyFilled($attributes);

        if ($draft === null && ! $hasContent) {
            return null;
        }

        if ($draft === null) {
            $draft = Client::create([
                // The name field if it has one, a placeholder if it does not.
                // The assistant renames it the moment a document tells it what
                // the brand is actually called.
                'name' => $name !== '' ? $name : self::PLACEHOLDER_NAME,
                'status' => ClientStatus::Borrador,

                // ⚠️ THE REST OF THE FORM GOES IN THE SAME BREATH. This used to
                // create the row from the name alone and return, so the first
                // save — the one that brings the draft into being, and the only
                // one that ever sees a screen filled in before anything was
                // stored — wrote the name and dropped the industry, the contact
                // and the notes on the floor. The page had already been told
                // "Borrador guardado", and it marks that snapshot as saved, so
                // it never posts those fields again: what was typed before the
                // first save was gone for good, silently.
                ...$this->written($attributes),
            ]);

            // Whoever started it is put on it, or an Equipo member would be
            // locked out of the draft they are in the middle of writing.
            $draft->staff()->attach($author->id);

            return $draft->fresh();
        }

        $this->update($draft, $attributes);

        return $draft;
    }

    /**
     * Apply the form to an existing draft.
     *
     * The placeholder is replaced the first time a real name arrives, and the
     * slug follows it — a draft that got named "The Coffee Club" should not
     * keep living at /marca-sin-nombre.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Client $draft, array $attributes): void
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name !== '' && $name !== $draft->name) {
            $attributes['slug'] = $draft->name === self::PLACEHOLDER_NAME
                ? Client::uniqueSlug($name)
                : $draft->slug;
        } else {
            unset($attributes['name']);
        }

        $draft->fill(array_filter(
            $attributes,
            fn ($value, $key) => $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        ))->save();
    }

    /**
     * Finish a draft: it stops being one and becomes a brand.
     *
     * onboarded_at is stamped here rather than at creation, because that is
     * the date somebody means when they ask how long a brand has been with
     * Breakfast — not the afternoon a half-filled form was abandoned.
     */
    public function finish(Client $draft, ClientStatus $status = ClientStatus::Activo): Client
    {
        $draft->update([
            'status' => $status,
            'onboarded_at' => $draft->onboarded_at ?? now(),
        ]);

        return $draft->fresh();
    }

    /**
     * The form's own columns that actually say something.
     *
     * Blank is dropped rather than written: on a draft an empty box means "not
     * yet", so letting one through would overwrite what the assistant read out
     * of a brandbook with the field somebody had not got to. Same rule as the
     * filter in update(), which is why both go through here.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function written(array $attributes): array
    {
        return array_filter(
            array_intersect_key($attributes, array_flip(self::COLUMNS)),
            fn ($value) => trim((string) $value) !== '',
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function anyFilled(array $attributes): bool
    {
        return $this->written($attributes) !== [];
    }
}
