{{--
    The errors from one named validation bag, beside the form that caused them.

    The layout prints $errors for the default bag, which is what an ordinary
    admin form uses. A screen carrying several independent forms — /admin/cuenta
    — validates into a bag per form instead, or a mistyped password would light
    up the name field at the top of the page.
--}}
@props(['bag'])

@php $messages = $errors->getBag($bag); @endphp

@if ($messages->any())
    <div class="admin-note admin-note-bad" role="alert">
        <ul>
            @foreach ($messages->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
