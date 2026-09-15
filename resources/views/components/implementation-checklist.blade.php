{{--
    A brand's implementation checklist — SEG-05 of the beta review.

    ⚠️ NOT x-admin.brand-checklist, which is a different thing with a
    confusingly similar name: that one is the list of brands a STAFF MEMBER may
    work on. This is the entregable "Checklist de implementación" — the steps a
    brand works through — and it is named for what it is so the two cannot be
    picked up by mistake.

    ONE COMPONENT, TWO SIDES. The client gets checkboxes; Breakfast gets the
    same list read-only, with who ticked each item and when — which is the
    "seguimiento" half of the requirement. Two components would be two
    definitions of what an item is, drifting apart the first time one of them
    changed.

    The TEXT is Breakfast's, always: it is one of the 48 entregables, written on
    the process board. Nothing here can change a word of it.

    Props:
      checklist  App\Services\Checklist — the parsed items
      ticks      keyed by item_key: the ChecklistTick rows for this brand
      editable   true on the client's page, where the boxes actually post
      action     where the form posts (ignored unless editable)
--}}
@props([
    'checklist',
    'ticks' => [],
    'editable' => false,
    'action' => null,
])

@if (! $checklist->isEmpty())
    @php
        $done = collect($checklist->items)
            ->filter(fn ($item) => isset($ticks[$item->key]))
            ->count();
    @endphp

    <section class="checklist">
        <header class="checklist-head">
            <h3>Checklist de implementación</h3>
            {{-- A count the client CAN act on, unlike "25 de 48" — see the note
                 on portal/estrategia.blade.php. These are their own items and
                 every one of them is something a person can go and do. --}}
            <p class="checklist-count">{{ $done }} de {{ $checklist->count() }}</p>
        </header>

        @if ($editable)
            <form method="POST" action="{{ $action }}" class="checklist-form" data-checklist>
                @csrf

                <ul class="checklist-items">
                    @foreach ($checklist->items as $item)
                        <li class="checklist-item">
                            <label>
                                {{-- The whole list posts every time, so
                                     unticking is a key not coming back. --}}
                                <input type="checkbox"
                                       name="items[]"
                                       value="{{ $item->key }}"
                                       @checked(isset($ticks[$item->key]))>
                                <span>{{ $item->label }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>

                {{-- Submits on change via checklist.js; this is what it looks
                     like with JavaScript off, and it still works. --}}
                <button type="submit" class="dashboard-button checklist-save">
                    Guardar checklist
                </button>
            </form>
        @else
            <ul class="checklist-items">
                @foreach ($checklist->items as $item)
                    @php $tick = $ticks[$item->key] ?? null; @endphp

                    <li class="checklist-item @if($tick) is-done @endif">
                        <span class="checklist-mark" aria-hidden="true">
                            @if ($tick)
                                <x-tabler-square-check />
                            @else
                                <x-tabler-square />
                            @endif
                        </span>
                        <span>
                            {{ $item->label }}
                            @if ($tick)
                                <span class="checklist-who">
                                    {{ $tick->checkedBy?->firstName() ?? 'La marca' }},
                                    {{ $tick->checked_at->translatedFormat('j M') }}
                                </span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
