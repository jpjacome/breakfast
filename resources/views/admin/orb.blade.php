{{--
    Test bench for the assistant orb.

    Lives inside the admin shell rather than as a loose HTML file so it runs
    over HTTP with the real theme tokens around it — a standalone file opened
    from disk cannot load ES modules at all, which is how this page came to
    exist.

    The controls are empty on purpose: resources/js/orb-demo.js fills each
    panel from its own spec, so adding a tunable never means editing this file.
    Delete the page, its route and that entry once the orb is mounted in the
    assistant panel for real.
--}}
<x-layouts.app title="Orbe" heading="orbe del asistente" :scripts="['resources/js/orb-demo.js']">

    <div class="admin-page-head">
        <div>
            <p class="admin-eyebrow">IA Studio</p>
            <h2 class="admin-title">Los tres estados</h2>
            <p class="admin-lead">
                Una sola malla en los tres: 12 vértices y 30 aristas de principio a fin.
                El octaedro no es otra figura — son los mismos 12 vértices plegados 2 a 1
                sobre los ejes, que es lo que permite que la transición sea una
                deformación real y no un fundido entre dos objetos.
            </p>
        </div>
    </div>

    <div class="orb-bench">

        {{-- ------------------------------------------------------------
             Left: the orb itself, and the state it is in
             ------------------------------------------------------------ --}}
        <div class="orb-side">
            <section class="admin-card orb-stage">
                {{--
                    Every setting can be pinned right here, and the sliders pick
                    up whatever this says as their starting point:

                        <x-assistant-orb line-width="4" size="1.2" />

                    Left bare so the bench always opens on the committed
                    defaults — that is what makes "Restablecer" mean something.
                --}}
                <x-assistant-orb />
                <span class="orb-state" data-orb-state>reposo</span>
            </section>

            <div class="orb-controls">
                <button type="button" class="admin-button admin-button-outline admin-button-sm"
                        data-orb-set="reposo" aria-pressed="true">Reposo</button>
                <button type="button" class="admin-button admin-button-outline admin-button-sm"
                        data-orb-set="pensando" aria-pressed="false">Pensando</button>
                <button type="button" class="admin-button admin-button-outline admin-button-sm"
                        data-orb-set="respondiendo" aria-pressed="false">Respondiendo</button>
                <button type="button" class="admin-button admin-button-ghost admin-button-sm"
                        data-orb-cycle aria-pressed="false">Ciclar cada 3s</button>
            </div>

            <p class="admin-hint" style="margin-top:1rem">
                Los deslizadores actúan sobre el orbe en vivo: no hay que guardar ni
                recargar. El bloque de abajo se reescribe con cada cambio — cuando los
                números estén bien, cópialo sobre <code>ORB_DEFAULTS</code> en
                <code>resources/js/assistant-orb.js</code> y eso queda como el
                comportamiento de serie.
            </p>

            <p class="admin-hint" style="margin-top:.75rem">
                Para una pantalla concreta no hace falta tocar el módulo: el componente
                acepta los ajustes desde la propia vista, y lo que diga la vista manda
                sobre los valores de serie.
                <br>
                <code>&lt;x-assistant-orb line-width="4" size="1.2" state="pensando" /&gt;</code>
            </p>
        </div>

        {{-- ------------------------------------------------------------
             Right: one panel per state, plus the globals
             ------------------------------------------------------------ --}}
        <div class="orb-side">

            @foreach (['reposo', 'pensando', 'respondiendo'] as $state)
                <section class="admin-card orb-panel" data-orb-panel="{{ $state }}">
                    <h3 class="brand-block-title">{{ $state }}</h3>
                </section>
            @endforeach

            <section class="admin-card orb-panel" data-orb-panel="global">
                <h3 class="brand-block-title">general</h3>
            </section>

        </div>
    </div>

    {{-- ------------------------------------------------------------
         The output: what to paste back into the module
         ------------------------------------------------------------ --}}
    <section class="admin-card" style="margin-top:1.5rem">
        <div class="admin-row admin-row-between" style="margin-bottom:1rem">
            <h3 class="brand-block-title">ajustes actuales</h3>
            <div class="admin-row" style="gap:.5rem">
                <button type="button" class="admin-button admin-button-ghost admin-button-sm"
                        data-orb-reset>Restablecer</button>
                <button type="button" class="admin-button admin-button-secondary admin-button-sm"
                        data-orb-copy>Copiar</button>
            </div>
        </div>

        <pre class="orb-output" data-orb-output></pre>
    </section>

</x-layouts.app>
