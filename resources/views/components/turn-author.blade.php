{{--
    Who said this, on the one thread that has more than one author.

    ⚠️ ONLY THE PROCESS BOARD NEEDS THIS. The other two assistants key their
    threads on user_id, so every turn in them is yours by construction and a
    byline would state the obvious. This thread belongs to the BRAND: everyone
    at Breakfast working it writes into the same conversation, so "was this me
    or Andrea" is a real question the screen has to answer.

    The monogram is `.admin-avatar`, the same mark the staff roster and the
    account screen use — a person should look the same everywhere they appear.
    Initials are what you read at a glance; the full name is on the element for
    anyone who does not recognise two letters.
--}}

@props(['author' => null, 'assistant' => false])

<p class="turn-author">
    @if ($assistant)
        {{--
            Brandy's mark. A placeholder until her avatar lands — when it does,
            this span becomes an <img> and nothing else on the screen changes.
            Kept as the same shape and size so the swap is one line and the
            layout does not move.
        --}}
        <span class="admin-avatar turn-author-brandy" aria-hidden="true">B</span>
        <span>Brandy</span>
    @elseif ($author)
        <span class="admin-avatar" aria-hidden="true">{{ $author->initials() }}</span>
        <span>{{ $author->name }}</span>
    @else
        {{-- A turn whose author is gone: the account was deleted, or the row
             predates user_id being written. The turn is still part of the
             record, so it keeps its place and says only what is known. --}}
        <span class="admin-avatar turn-author-gone" aria-hidden="true">·</span>
        <span>Alguien del equipo</span>
    @endif
</p>
