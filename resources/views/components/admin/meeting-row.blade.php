{{--
    One meeting, as it reads on a brand's page and on /admin/reuniones.

    Props:
      meeting
      client   the brand it belongs to — the cancel and delete routes are
               brand-scoped, which is what 'covers-client' guards on
      brand     true on the roster, where a row without its brand's name is
                a meeting with nobody in particular
--}}
@props(['meeting', 'client', 'brand' => false])

<li class="meeting @if($meeting->isCancelled()) meeting-off @elseif($meeting->isUpcoming()) meeting-next @endif">
    <div>
        @if ($brand)
            {{-- Before the title, not after: on a list that crosses brands, WHOSE
                 meeting this is is the first thing being looked for. --}}
            <a href="{{ route('admin.clients.show', $client) }}" class="meeting-brand">
                {{ $client->name }}
            </a>
        @endif

        <b>{{ $meeting->title }}</b>
        <p class="admin-meta">
            {{ ucfirst($meeting->whenInWords()) }}
            @if ($meeting->isCancelled())
                · cancelada
            @elseif (! $meeting->isUpcoming())
                · ya pasó
            @endif
        </p>
        @if ($meeting->agenda)
            <p class="admin-hint">{{ $meeting->agenda }}</p>
        @endif
        @if ($meeting->link && ! $meeting->isCancelled())
            <a href="{{ $meeting->link }}" class="admin-meta" target="_blank" rel="noopener">
                {{ $meeting->link }}
            </a>
        @endif

        {{-- ⚠️ WHICH REMINDERS ACTUALLY WENT OUT — SEG-01.

             Printed rather than assumed, and that is the whole reason it is
             here. The reminders depend on a cPanel cron that may not exist
             (CLAUDE.md §3), and a feature whose failure mode is silence is one
             where everybody believes the client was warned until the client
             says otherwise. If these stay empty on a meeting that is tomorrow,
             the cron is not running. --}}
        @if ($meeting->isUpcoming() && $meeting->reminders->isNotEmpty())
            <p class="admin-hint">
                Avisos enviados:
                {{ $meeting->reminders->map(fn ($r) => $r->window->label())->implode(', ') }}
            </p>
        @endif
    </div>

    <div class="meeting-actions">
        @unless ($meeting->isCancelled())
            <form method="POST"
                  action="{{ route('admin.clients.meetings.cancel', [$client, $meeting]) }}">
                @csrf
                <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                    Cancelar
                </button>
            </form>
        @endunless
        <form method="POST"
              action="{{ route('admin.clients.meetings.destroy', [$client, $meeting]) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="admin-button admin-button-ghost admin-button-sm">
                Eliminar
            </button>
        </form>
    </div>
</li>
