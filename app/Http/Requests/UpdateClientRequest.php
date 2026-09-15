<?php

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Editing a brand's own details after it exists.
 *
 * Separate from StoreClientRequest rather than shared, because the two forms
 * are not the same form: creating carries the draft it came from and the 48
 * entregables the assistant filled in on that screen, and neither of those
 * means anything here. The entregables have their own screen once a brand is
 * real, and a live brand is not a draft.
 *
 * ⚠️ THE SLUG IS NOT EDITABLE, and renaming does not move it. It is the brand's
 * address in three places that outlive a rename: the URLs people bookmark and
 * paste to each other, the asset folder on disk (storage/app/marcas/{slug}, see
 * BrandAsset::folderFor) and the FTP listing that folder is administered
 * through. Renaming "Patito" to "Patito Studio" should change a label, not
 * strand a folder of logos under the old name.
 */
class UpdateClientRequest extends FormRequest
{
    /** The route is behind auth, 'breakfast' and 'covers-client' already. */
    public function authorize(): bool
    {
        return $this->user()?->isBreakfast() ?? false;
    }

    /**
     * Namespaced under brand[...] on purpose.
     *
     * The brand page also carries the invite form, which posts its own `name`
     * and `email`. Flat keys would make the two share one old() slot, so a
     * failed invite would repopulate the brand's name with the invitee's. Same
     * shape the new-brand screen already posts — see BrandOnboardingTurnRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand.name' => ['required', 'string', 'max:120'],
            'brand.industry' => ['nullable', 'string', 'max:80'],
            // '' is "sin definir" and is a real answer — see clientAttributes().
            'brand.trademark_registered' => ['nullable', 'in:0,1'],
            'brand.status' => ['required', new Enum(ClientStatus::class)],
            'brand.contact_name' => ['nullable', 'string', 'max:120'],
            'brand.contact_email' => ['nullable', 'email', 'max:190'],
            'brand.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The columns to write. Blanks are stored as null rather than '', so
     * "sin contacto registrado" has one representation instead of two.
     *
     * @return array<string, mixed>
     */
    public function clientAttributes(): array
    {
        $values = (array) $this->safe()->input('brand', []);

        foreach (['industry', 'contact_name', 'contact_email', 'notes'] as $field) {
            $values[$field] = trim((string) ($values[$field] ?? '')) ?: null;
        }

        // Three states, and the empty string is the third. Not folded in with
        // the loop above because '' → null is the same result by a different
        // argument: there it is "nothing was typed", here it is somebody
        // choosing "sin definir", and a boolean cast would turn both into false.
        $trademark = (string) ($values['trademark_registered'] ?? '');
        $values['trademark_registered'] = $trademark === '' ? null : $trademark === '1';

        // A live brand cannot be sent back to Borrador: "unfinished" is a state
        // a brand leaves once and never returns to, and the new-brand screen is
        // the only thing that understands a draft. Same rule as
        // StoreClientRequest::chosenStatus().
        $status = ClientStatus::tryFrom((string) $values['status']);

        if ($status === null || $status->isDraft()) {
            $values['status'] = $this->route('client')->status->value;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand.name' => 'nombre de la marca',
            'brand.industry' => 'industria',
            'brand.status' => 'estado',
            'brand.contact_name' => 'nombre de contacto',
            'brand.contact_email' => 'correo de contacto',
            'brand.notes' => 'notas',
        ];
    }
}
