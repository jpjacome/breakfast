{{--
    Session status + validation errors for the auth screens.
    Kept in one component so every auth view reports failures identically.
--}}

@if (session('status'))
    <div class="alert alert--success" role="status" style="margin-bottom: var(--space-5);">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="alert alert--danger" role="alert" style="margin-bottom: var(--space-5);">
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
