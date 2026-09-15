@props([
    // Whose permissions are being edited. Null on an invite form.
    'user' => null,
    // Who is doing the granting. Their own access is the ceiling.
    'grantor' => null,
    // Tick everything at Ver when there is nothing to pre-fill from. Used for
    // the brand owner, who should see their whole brand by default.
    'readByDefault' => false,
])

@php
    use App\Enums\AccessLevel;

    $grantor ??= auth()->user();

    // Only what this grantor can actually pass on. For Breakfast staff that is
    // all nine; for a brand owner it is the subset they were given, so a
    // read-only owner never sees a row they could not fill in.
    $sections = $grantor->grantableSections();
@endphp

{{--
    The checkbox grid: one row per section the grantor can delegate, one box
    each.

    One box, not two. The client side of the portal is read-only throughout —
    Breakfast writes a brand, the brand reads it — so the only question a grant
    answers is whether the section opens at all. See User::grantCeiling(), which
    is where that is decided; this grid just draws it.

    Rendered by every form that hands out access — Breakfast staff in /admin
    and the brand owner in /portal/equipo — so the two can never drift apart.

    Nothing here is the security boundary. ValidatesSectionPermissions re-derives
    the ceiling from the grantor on the way in, so a hand-edited checkbox is
    clamped or dropped server-side. This only keeps the form honest about what
    is on offer.
--}}

<fieldset class="permissions">
    <legend class="permissions-legend">Acceso por sección</legend>

    <p class="permissions-note">
        Marca las secciones que quieres que vea en su portal. Lo que no marques,
        no aparece. El portal del cliente es de sólo lectura: nada de lo que
        marques aquí le deja cambiar cosas.
    </p>

    @if ($sections)
        <div class="permissions-grid">
            <div class="permissions-header" aria-hidden="true">
                <span>Sección</span>
                <span>Ver</span>
            </div>

            @foreach ($sections as $section)
                @php
                    $current = $user?->accessTo($section);
                    $name = "permissions[{$section->value}][]";

                    // An invite form with nothing to pre-fill: the brand owner
                    // starts with everything at Ver, a teammate with nothing.
                    $readTicked = $user
                        ? $current?->covers(AccessLevel::Read)
                        : $readByDefault;
                @endphp

                <div class="permissions-row">
                    <span class="permissions-section">
                        <x-dynamic-component :component="'tabler-'.$section->icon()" aria-hidden="true" />
                        <span>
                            <b>{{ $section->label() }}</b>
                            <span class="permissions-description">{{ $section->description() }}</span>
                        </span>
                    </span>

                    {{-- The visible label text is the section name, not "Ver": on
                         its own, a column of identical "Ver" checkboxes is
                         unusable with a screen reader. --}}
                    <label class="permissions-box">
                        <input type="checkbox" name="{{ $name }}" value="{{ AccessLevel::Read->value }}"
                               @checked($readTicked)>
                        <span class="screen-reader-only">Ver {{ $section->label() }}</span>
                    </label>
                </div>
            @endforeach
        </div>

        @unless ($grantor->isBreakfast())
            <p class="permissions-note permissions-ceiling">
                <x-tabler-lock aria-hidden="true" />
                Sólo puedes dar lo que tú tienes. Las secciones que no aparecen
                no son tuyas para repartir.
            </p>
        @endunless
    @else
        {{-- A brand owner granted nothing. They can still run their team page,
             they just have nothing to share yet. --}}
        <p class="dashboard-empty">
            Todavía no tienes secciones que puedas compartir. Escríbenos y las
            habilitamos para tu marca.
        </p>
    @endif
</fieldset>
