{{--
    Answering an invitation to a brand — queued item B.

    ⚠️ TWO BUTTONS, EQUALLY WEIGHTED. Saying no has to be as easy as saying
    yes, or the consent this whole flow exists to collect is not consent. So
    "No, gracias" is a real button rather than a link in small print, and
    nothing on this page asks for a reason.

    The person is not in this brand yet, so there is no sidebar to show them and
    no section to gate on — the token and their address are the authorisation.
    It wears the portal shell anyway, because the alternative is a page that
    looks like nothing else in the app, asking somebody to click yes.
--}}
<x-layouts.portal title="Invitación">

    <header class="dashboard-head">
        <h1>Te invitaron a {{ $invitation->client->name }}</h1>
        <p>
            @if ($invitation->inviter)
                {{ $invitation->inviter->name }} quiere sumarte a esta marca.
            @else
                Alguien quiere sumarte a esta marca.
            @endif
            Tu cuenta no cambió: no vas a ver nada de {{ $invitation->client->name }}
            hasta que aceptes.
        </p>
    </header>

    <section class="dashboard-card invitation-card">

        <p class="invitation-role">
            Entrarías como
            <b>{{ $invitation->role->label() }}</b>.
        </p>

        <div class="invitation-actions">
            <form method="POST" action="{{ route('portal.invitaciones.accept', $invitation->token) }}">
                @csrf
                <button type="submit" class="dashboard-button">
                    Aceptar y entrar
                </button>
            </form>

            <form method="POST" action="{{ route('portal.invitaciones.decline', $invitation->token) }}">
                @csrf
                <button type="submit" class="dashboard-button dashboard-button-quiet">
                    No, gracias
                </button>
            </form>
        </div>

        <p class="invitation-note">
            Si dices que no, no se le avisa a nadie más que a ti mismo, y esta
            invitación se cierra.
        </p>

    </section>

</x-layouts.portal>
