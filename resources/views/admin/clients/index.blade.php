<x-layouts.app title="Clientes" heading="clientes">

    <x-slot:actions>
        <a href="{{ route('admin.clients.create') }}" class="bkf-btn bkf-btn--primary">Nueva marca</a>
    </x-slot:actions>

    <div class="page-head">
        <div>
            <p class="bkf-eyebrow">{{ $clients->total() }} {{ Str::plural('marca', $clients->total()) }}</p>
            <h2 class="bkf-h1" style="margin-top:var(--space-2);">Marcas</h2>
        </div>

        <form method="GET" action="{{ route('admin.clients.index') }}" class="filters">
            <div class="bkf-field">
                <label class="bkf-label bkf-sr-only" for="q">Buscar</label>
                <input class="bkf-input" id="q" name="q" type="search"
                       placeholder="Buscar marca, industria o correo" value="{{ $q }}">
            </div>
            <div class="bkf-field">
                <label class="bkf-label bkf-sr-only" for="status">Estado</label>
                <select class="bkf-select" id="status" name="status">
                    <option value="">Todos los estados</option>
                    @foreach($statuses as $case)
                        <option value="{{ $case->value }}" @selected($status === $case->value)>
                            {{ $case->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bkf-btn bkf-btn--outline">Filtrar</button>
            @if($q || $status)
                <a href="{{ route('admin.clients.index') }}" class="bkf-btn bkf-btn--ghost">Limpiar</a>
            @endif
        </form>
    </div>

    @if($clients->isEmpty())
        <div class="empty">
            <p>
                @if($q || $status)
                    Ninguna marca coincide con ese filtro.
                @else
                    Todavía no hay marcas registradas.
                @endif
            </p>
            <a href="{{ route('admin.clients.create') }}" class="bkf-btn bkf-btn--primary bkf-btn--sm" style="margin-top:var(--space-4);">
                Nueva marca
            </a>
        </div>
    @else
        <div class="bkf-card" style="padding:0;overflow:hidden;">
            <div class="bkf-table-wrap">
                <table class="bkf-table">
                    <thead>
                        <tr>
                            <th>Marca</th>
                            <th>Industria</th>
                            <th>Contacto</th>
                            <th>Contexto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clients as $client)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.clients.show', $client) }}" class="bkf-row" style="gap:var(--space-3);">
                                        <span class="monogram">{{ $client->initials() }}</span>
                                        <span>
                                            <b>{{ $client->name }}</b><br>
                                            <span class="bkf-meta">/{{ $client->slug }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td>{{ $client->industry ?: '—' }}</td>
                                <td>
                                    @if($client->contact_name || $client->contact_email)
                                        {{ $client->contact_name }}<br>
                                        <span class="bkf-meta">{{ $client->contact_email }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="bkf-tabular">
                                    {{ $client->context_documents_count }}
                                    {{ Str::plural('archivo', $client->context_documents_count) }}
                                </td>
                                <td>
                                    <span class="bkf-badge {{ $client->status->badgeClass() }}">
                                        {{ $client->status->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:var(--space-5);">
            {{ $clients->links() }}
        </div>
    @endif

</x-layouts.app>
