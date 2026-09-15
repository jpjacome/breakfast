{{--
    A brand's meetings: the form to add one, and everything already on the
    books.

    It used to live on the process screen. It moved to the brand's own page
    because that is where somebody goes to deal with the brand rather than with
    the work — the process screen is the three steps and the 48 entregables,
    and a meeting is neither.

    A component rather than markup in one blade: /admin/reuniones renders the
    same list across every brand, and two copies of a meeting row would drift.

    Props:
      client    the brand these belong to
      meetings  its meetings, newest first
      audience  how many people in the brand can read Reuniones — nobody ticks
                "avisar" for an audience of nobody and assumes it went out
--}}
@props(['client', 'meetings', 'audience'])

<section class="meetings" aria-label="Reuniones">

    <header class="process-steps-head">
        <h3 class="admin-heading">reuniones</h3>
        <p class="admin-hint">
            @if ($audience === 0)
                Esta marca todavía no tiene a nadie que pueda ver Reuniones, así que
                no hay a quién avisar. Agenda igual: lo verán cuando entren.
            @else
                Se avisa a {{ $audience }}
                {{ Str::plural('persona', $audience) }} de la marca —
                quienes pueden ver Reuniones. Tú eliges por dónde.
            @endif
        </p>
    </header>

    <form method="POST" action="{{ route('admin.clients.meetings.store', $client) }}"
          class="admin-card meeting-form">
        @csrf

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

        {{-- Ticked by default: telling the client is the normal case, and
             the exception is the one worth a deliberate click. --}}
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

    @if ($meetings->isNotEmpty())
        <ul class="meeting-list">
            @foreach ($meetings as $meeting)
                <x-admin.meeting-row :meeting="$meeting" :client="$client" />
            @endforeach
        </ul>
    @endif

</section>
