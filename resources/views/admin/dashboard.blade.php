{{--
    The Breakfast home screen.

    The assistant is the screen; everything that used to be here — the counts,
    the recent brands, the recent files — is a footer under it. That ordering is
    the product opinion: this back office exists so the AI knows the brands, so
    the AI is what you land on.

    Brandy is the same agent the client talks to, on the other side of the
    desk: there she answers about one brand from its entregables, here about
    every brand from the database. One name, two contexts — never two agents.
    See docs/asistente-admin.md and resources/js/assistant.js.
--}}
@php
    use App\Services\Ai\Data\UsageSummary;
    use Illuminate\Support\Number;
@endphp

<x-layouts.app title="Inicio" heading="inicio" :css="['assistant', 'attachments']"
               :scripts="['resources/js/assistant.js', 'resources/js/lightbox.js']">

    <x-slot:actions>
        <a href="{{ route('admin.clients.create') }}" class="admin-button admin-button-primary">
            Nueva marca
        </a>
    </x-slot:actions>

    {{-- The assistant is the screen. Same component as /clientes/nueva — one
         agent, one face. --}}
    <x-admin.assistant
        :brands="$brands"
        intro="Soy Brandy. Pregúntame lo que quieras sobre las marcas de Breakfast." />

    {{-- ================================================================
         Everything else, small, underneath
         ================================================================ --}}
    <section class="dash-foot">

        <div class="dash-stats">
            <p class="dash-stat">
                <span class="dash-stat-value admin-numbers">{{ $clientCount }}</span>
                <span class="admin-eyebrow">{{ Str::plural('marca', $clientCount) }}</span>
            </p>
            <p class="dash-stat">
                <span class="dash-stat-value admin-numbers">{{ $activeCount }}</span>
                <span class="admin-eyebrow">{{ Str::plural('activa', $activeCount) }}</span>
            </p>
        </div>

        {{-- ------------------------------------------------------------
             What the AI is costing
             ------------------------------------------------------------ --}}
        <section>
            <div class="admin-row admin-row-between" style="margin-bottom:1rem">
                <h3 class="admin-heading">uso de ia · {{ $usageDays }} días</h3>

                {{-- Only with a management key configured. Our ledger can say
                     what was spent; only OpenRouter knows what is left. --}}
                @if($credits)
                    <span class="admin-meta">
                        Saldo OpenRouter
                        <b class="admin-numbers">${{ number_format($credits['remaining'], 2) }}</b>
                        de ${{ number_format($credits['purchased'], 2) }}
                    </span>
                @endif
            </div>

            <div class="dash-stats">
                <p class="dash-stat">
                    <span class="dash-stat-value admin-numbers">{{ $usage->formattedUsd() }}</span>
                    <span class="admin-eyebrow">
                        gasto
                        {{-- Say which number this is. DeepSeek does not report
                             cost, so its rows are priced from config/ai.php and
                             calling that "spent" would be a lie on a screen
                             that looks like an invoice. --}}
                        @unless($usage->costIsExact()) estimado @endunless
                    </span>
                </p>
                <p class="dash-stat">
                    <span class="dash-stat-value admin-numbers">{{ $usageToday->formattedUsd() }}</span>
                    <span class="admin-eyebrow">hoy</span>
                </p>
                <p class="dash-stat">
                    <span class="dash-stat-value admin-numbers">{{ Number::abbreviate($usage->totalTokens(), precision: 1) }}</span>
                    <span class="admin-eyebrow">tokens</span>
                </p>
                <p class="dash-stat">
                    <span class="dash-stat-value admin-numbers">{{ round($usage->cacheHitRate() * 100) }}%</span>
                    <span class="admin-eyebrow">en caché</span>
                </p>
                <p class="dash-stat">
                    <span class="dash-stat-value admin-numbers">{{ $usage->requests }}</span>
                    <span class="admin-eyebrow">
                        {{ Str::plural('consulta', $usage->requests) }}
                        @if($usage->failures > 0)
                            · <span class="admin-flag">{{ $usage->failures }} {{ Str::plural('fallo', $usage->failures) }}</span>
                        @endif
                    </span>
                </p>
            </div>

            @if($usage->requests === 0)
                <div class="admin-empty" style="margin-top:1.25rem">
                    <p>Todavía no hay consultas registradas en este periodo.</p>
                </div>
            @else
                <div class="dash-lists" style="margin-top:1.25rem">

                    <section>
                        <h4 class="admin-eyebrow" style="margin-bottom:.5rem">Por modelo</h4>
                        @foreach($usageByModel as $row)
                            <div class="admin-doc">
                                <span class="admin-doc-main">
                                    <span class="admin-doc-title">{{ $row->model }}</span>
                                    <span class="admin-doc-meta">
                                        {{ $row->requests }} {{ Str::plural('consulta', $row->requests) }}
                                        · {{ Number::abbreviate((int) $row->tokens, precision: 1) }} tokens
                                    </span>
                                </span>
                                <span class="admin-numbers">{{ UsageSummary::formatMicroUsd((int) $row->cost_micro_usd) }}</span>
                            </div>
                        @endforeach
                    </section>

                    <section>
                        <h4 class="admin-eyebrow" style="margin-bottom:.5rem">Por marca</h4>
                        @foreach($usageByClient as $row)
                            <div class="admin-doc">
                                <span class="admin-doc-main">
                                    {{-- A null client is a roster-wide question
                                         asked from this screen: Breakfast's own
                                         cost, not any one brand's. --}}
                                    <span class="admin-doc-title">{{ $row->client_name ?? 'Sin marca' }}</span>
                                    <span class="admin-doc-meta">
                                        {{ $row->requests }} {{ Str::plural('consulta', $row->requests) }}
                                    </span>
                                </span>
                                <span class="admin-numbers">{{ UsageSummary::formatMicroUsd((int) $row->cost_micro_usd) }}</span>
                            </div>
                        @endforeach
                    </section>

                </div>
            @endif
        </section>

        <div class="dash-lists">

            <section>
                <div class="admin-row admin-row-between" style="margin-bottom:.75rem">
                    <h3 class="admin-heading">marcas recientes</h3>
                    @if($recentClients->isNotEmpty())
                        <a href="{{ route('admin.clients.index') }}" class="admin-link">Ver todas</a>
                    @endif
                </div>

                @forelse($recentClients as $client)
                    <a href="{{ route('admin.clients.show', $client) }}" class="admin-doc">
                        <span class="admin-monogram">{{ $client->initials() }}</span>
                        <span class="admin-doc-main">
                            <span class="admin-doc-title">{{ $client->name }}</span>
                            <span class="admin-doc-meta">
                                {{ $client->industry ?: 'Sin industria' }}
                                · {{ $client->brand_assets_count }}
                                {{ Str::plural('archivo', $client->brand_assets_count) }}
                            </span>
                        </span>
                        <span class="admin-badge {{ $client->status->badgeClass() }}">{{ $client->status->label() }}</span>
                    </a>
                @empty
                    <div class="admin-empty">
                        <p>Todavía no hay marcas. Crea la primera para empezar.</p>
                        <a href="{{ route('admin.clients.create') }}"
                           class="admin-button admin-button-outline admin-button-sm"
                           style="margin-top:1rem">
                            Crear la primera marca
                        </a>
                    </div>
                @endforelse
            </section>

            <section>
                <h3 class="admin-heading" style="margin-bottom:.75rem">archivos recientes</h3>

                @forelse($recentAssets as $asset)
                    <div class="admin-doc">
                        <span class="admin-doc-kind">{{ $asset->extension() }}</span>
                        <span class="admin-doc-main">
                            <span class="admin-doc-title">{{ $asset->title }}</span>
                            <span class="admin-doc-meta">{{ $asset->client->name }} · {{ $asset->humanSize() }}</span>
                        </span>
                    </div>
                @empty
                    <div class="admin-empty">
                        <p>Todavía no has entregado archivos a ninguna marca.</p>
                    </div>
                @endforelse
            </section>

        </div>
    </section>

</x-layouts.app>
