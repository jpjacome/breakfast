{{--
    What a chat turn carried, drawn.

    ONE COMPONENT, THREE TRANSCRIPTS: Brandy on /portal, the dashboard
    assistant, and the onboarding assistant on the process board. They sit in
    two different shells, so this carries its own stylesheet — see
    `attachments.css` and CLAUDE.md §9. A file must not look like one thing on
    the admin side and another on the client's.

    ⚠️ ATTACHMENTS, NOT MODEL OUTPUT. Everything here was chosen by a person
    who picked a file. That is why it may become an <img> at all: the rule in
    assistant-text.js — no images, no outside links — governs what the MODEL
    writes, because a URL it invented is not a reference, it is a guess with a
    picture frame around it.
--}}

@props(['names' => null, 'ids' => null])

@php
    $items = app(App\Services\TurnAttachments::class)->for($names, $ids, auth()->user());
@endphp

@if ($items->isNotEmpty())
    <ul class="turn-files">
        @foreach ($items as $item)
            <li @class(['turn-file', 'turn-file-'.$item['kind']])>
                @if ($item['kind'] === 'image')
                    {{-- Streams through the gated route like every other read of
                         this file, so the thumbnail is no wider a door than the
                         link beside it. --}}
                    <button type="button" class="turn-file-open"
                            data-lightbox="{{ $item['asset']->url() }}"
                            data-lightbox-kind="image"
                            data-lightbox-title="{{ $item['name'] }}">
                        <img src="{{ $item['asset']->url() }}" alt="{{ $item['name'] }}" loading="lazy">
                        <span class="screen-reader-only">Ampliar {{ $item['name'] }}</span>
                    </button>

                @elseif ($item['kind'] === 'video')
                    {{--
                        preload="metadata" — the browser fetches the first frames
                        and paints one, which IS the preview. Never preload="auto":
                        a transcript of videos would drag every file through the
                        gated route in full just to draw the thread, on a host
                        whose worker pool is shared with the public site.

                        The link opens the file in its own tab rather than playing
                        it inline: a chat thread is a bad video player, and a tab
                        gives the browser's own controls for free.
                    --}}
                    <a href="{{ $item['asset']->url() }}" target="_blank" rel="noopener"
                       class="turn-file-open">
                        <video src="{{ $item['asset']->url() }}" preload="metadata"
                               muted playsinline tabindex="-1" aria-hidden="true"></video>
                        <span class="turn-file-play" aria-hidden="true">
                            <x-tabler-player-play />
                        </span>
                        <span class="screen-reader-only">Abrir {{ $item['name'] }} en otra pestaña</span>
                    </a>

                @elseif ($item['kind'] === 'file')
                    <a href="{{ $item['asset']->url() }}" target="_blank" rel="noopener"
                       class="turn-file-chip">
                        <x-dynamic-component :component="'tabler-'.$item['asset']->icon()" aria-hidden="true" />
                        <span>{{ $item['name'] }}</span>
                    </a>

                @else
                    {{-- A name and nothing else. Either the turn predates the
                         files being kept, or this viewer may not open it. Both
                         are states, not errors, and neither should render as a
                         broken image. --}}
                    <span class="turn-file-chip turn-file-gone">
                        <x-tabler-paperclip aria-hidden="true" />
                        <span>{{ $item['name'] }}</span>
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
