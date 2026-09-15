@props([
    // Every brand, in name order.
    'brands',
    // Whose assignment is being edited. Null on the invite form.
    'member' => null,
    // Unique per form on the page: two forms sharing a checkbox id would make
    // clicking one label tick the other form's box.
    'idPrefix' => 'brand',
])

@php
    // The edit forms read stored state, never old(): every member's form on
    // this page posts the same field names, so one failed edit would otherwise
    // repaint everybody's ticks with the rejected input. The invite form is
    // the only one that can safely restore what was typed.
    $selected = $member
        ? $member->assignedClients->pluck('id')->all()
        : array_map('intval', (array) old('clients', []));

    // An Admin reaches every brand by role, so the list is stored but does not
    // decide anything for them. Say so instead of letting the ticks imply a
    // restriction that is not enforced.
    $isAdmin = $member?->isAdmin() ?? false;
@endphp

{{--
    Which brands a staff member may work on.

    Only the Equipo role is actually scoped by this — see User::coversEveryBrand().
    Nothing here is the security boundary: EnsureStaffCoversClient re-checks the
    brand on every /admin route that names one, so an unticked box that gets
    hand-posted is refused server-side. This keeps the form honest about what
    somebody will be able to open.
--}}

<fieldset class="brand-checklist-field">
    <legend>Marcas</legend>

    @if($brands->isEmpty())
        <span class="admin-hint">Todavía no hay marcas que asignar.</span>
    @else
        <span class="admin-hint">
            @if($isAdmin)
                Un admin entra a todas las marcas, marques lo que marques. Esta
                lista se guarda y empieza a aplicar si pasa a Equipo.
            @else
                Sólo verá las marcas que marques aquí. Sin ninguna, no ve ninguna.
            @endif
        </span>

        <div class="brand-checklist">
            @foreach($brands as $brand)
                <label class="brand-checklist-item">
                    <input type="checkbox"
                           id="{{ $idPrefix }}_client_{{ $brand->id }}"
                           name="clients[]"
                           value="{{ $brand->id }}"
                           @checked(in_array($brand->id, $selected, true))>
                    <span>{{ $brand->name }}</span>
                </label>
            @endforeach
        </div>
    @endif
</fieldset>
