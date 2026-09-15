{{--
    /admin/reuniones/nueva — the same form the brand page carries, plus the one
    field it does not need: which brand.

    ⚠️ It posts to admin.clients.meetings.store, the brand-scoped route, with
    the chosen brand in the URL. There is no second store action: one door,
    guarded by 'covers-client' the same way it is everywhere else. That is why
    the brand is picked BEFORE anything is typed — the form's action has to
    name it.
--}}
<x-layouts.app title="Nueva reunión" heading="nueva reunión" css="meetings">

    <x-slot:actions>
        <a href="{{ route('admin.meetings.index') }}" class="admin-button admin-button-ghost admin-button-sm">
            ← Reuniones
        </a>
    </x-slot:actions>

    @if ($brands->isEmpty())
        <div class="admin-empty">
            <p>No hay marcas todavía. Crea una antes de agendar nada.</p>
            <a href="{{ route('admin.clients.create') }}"
               class="admin-button admin-button-outline admin-button-sm" style="margin-top:1rem">
                Nueva marca
            </a>
        </div>
    @else
        <div class="admin-card meeting-form" style="max-width:46rem">

            <div class="admin-field">
                <label for="brand">Marca</label>
                {{-- No JavaScript: choosing a brand navigates back here with it
                     selected, and the form below then posts to that brand's own
                     route. One reload beats a second store action that would
                     have to re-derive the permission check by hand. --}}
                <form method="GET" action="{{ route('admin.meetings.create') }}" class="admin-row" style="gap:.5rem">
                    <select id="brand" name="marca" required>
                        <option value="">Elige una marca…</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->slug }}" @selected($selected === $brand->slug)>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="admin-button admin-button-outline admin-button-sm">
                        Elegir
                    </button>
                </form>
            </div>

            @php $client = $brands->firstWhere('slug', $selected); @endphp

            @if ($client === null)
                <p class="admin-hint">Elige la marca y aparecen los demás campos.</p>
            @else
                @php $audience = $client->meetingAudience()->count(); @endphp

                <p class="admin-hint">
                    @if ($audience === 0)
                        {{ $client->name }} todavía no tiene a nadie que pueda ver Reuniones,
                        así que no hay a quién avisar. Agenda igual.
                    @else
                        Se avisa a {{ $audience }} {{ Str::plural('persona', $audience) }}
                        de {{ $client->name }} — quienes pueden ver Reuniones.
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.clients.meetings.store', $client) }}"
                      class="meeting-form">
                    @csrf
                    {{-- Whitelisted in the controller: it lands on the roster
                         rather than back on this empty form. --}}
                    <input type="hidden" name="desde" value="agenda">

                    <div class="admin-fields-pair">
                        <div class="admin-field">
                            <label for="meeting_title">Título</label>
                            <input id="meeting_title" name="title" type="text" required
                                   value="{{ old('title') }}" placeholder="Revisión de territorio">
                        </div>
                        <div class="admin-field">
                            <label for="meeting_at">Fecha y hora</label>
                            <input id="meeting_at" name="scheduled_at" type="datetime-local" required
                                   value="{{ old('scheduled_at') }}">
                        </div>
                    </div>

                    <div class="admin-field">
                        <label for="meeting_link">Link</label>
                        <input id="meeting_link" name="link" type="url"
                               value="{{ old('link') }}" placeholder="https://meet.google.com/…">
                    </div>

                    <div class="admin-field">
                        <label for="meeting_agenda">Agenda</label>
                        <textarea id="meeting_agenda" name="agenda" rows="3"
                                  placeholder="Qué vamos a ver, en dos líneas.">{{ old('agenda') }}</textarea>
                    </div>

                    <fieldset class="meeting-notify">
                        <legend>Avisar</legend>
                        <label>
                            <input type="checkbox" name="notify_portal" value="1"
                                   @checked(old('notify_portal', true))>
                            <span>En el portal</span>
                        </label>
                        <label>
                            <input type="checkbox" name="notify_mail" value="1"
                                   @checked(old('notify_mail', true))>
                            <span>Por correo</span>
                        </label>
                    </fieldset>

                    <button type="submit" class="admin-button admin-button-primary" style="justify-self:start">
                        Agendar reunión
                    </button>
                </form>
            @endif

        </div>
    @endif

</x-layouts.app>
