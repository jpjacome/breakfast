{{--
    The errors from one named validation bag, beside the form that caused them.

    The portal layout prints $errors for the DEFAULT bag, which is what an
    ordinary portal form uses. /portal/perfil carries two independent forms and
    validates into a bag each, or a mistyped current password would light up the
    name field at the top of the page — and a named bag is invisible to the
    layout's `$errors->any()`, so without this the form would fail silently.

    The portal twin of <x-admin.form-errors>, which does the same job in the
    other shell's vocabulary. Two files rather than one because the note class
    differs and a component may only use tokens both shells define.
--}}
@props(['bag'])

@php $messages = $errors->getBag($bag); @endphp

@if ($messages->any())
    <div class="dashboard-note dashboard-note-problem" role="alert">
        @if ($messages->count() === 1)
            {{ $messages->first() }}
        @else
            <strong>Revisa lo siguiente:</strong>
            <ul>
                @foreach ($messages->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
