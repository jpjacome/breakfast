{{--
    The one message box on an entrance screen: what the password broker said,
    or what failed validation.

    ONE BOX FOR BOTH, and never two at once — every one of these screens
    redirects with either a status or an error, so a page that could show a
    green notice above a red one would be showing a state that cannot happen.
    Black type on the brown panel reads as a stop either way, which is right:
    both are "read this before carrying on".

    Kept as a component so all six entrance screens report identically. Styled
    by .auth-status in auth.css, which every one of them loads.
--}}

@if (session('status'))
    <p class="auth-status" role="status">{{ session('status') }}</p>
@elseif ($errors->any())
    <div class="auth-status" role="alert">
        @if ($errors->count() === 1)
            {{ $errors->first() }}
        @else
            <strong>Revisa lo siguiente:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
