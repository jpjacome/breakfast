<x-layouts.app title="Clientes" heading="clientes">

    <x-slot:actions>
        <a href="{{ route('admin.clients.create') }}" class="admin-button admin-button-primary">Nueva marca</a>
    </x-slot:actions>

    <div class="admin-page-head">
        <div>
            <p class="admin-eyebrow">{{ $clients->total() }} {{ Str::plural('marca', $clients->total()) }}</p>
            <h2 class="admin-title">Marcas</h2>
        </div>

        <form method="GET" action="{{ route('admin.clients.index') }}" class="admin-filters">
            <div class="admin-field">
                <label class="screen-reader-only" for="q">Buscar</label>
                <input id="q" name="q" type="search"
                       placeholder="Buscar marca, industria o correo" value="{{ $q }}">
            </div>
            <div class="admin-field">
                <label class="screen-reader-only" for="status">Estado</label>
                <select id="status" name="status">
                    <option value="">Todos los estados</option>
                    @foreach($statuses as $case)
                        <option value="{{ $case->value }}" @selected($status === $case->value)>
                            {{ $case->label() }}
                        </option>
                    @endforeach
                    {{-- Not a status: the soft-delete state, sharing the box
                         because it is the same question a person is asking —
                         "show me a different set of brands". --}}
                    <option value="papelera" @selected($status === 'papelera')>Papelera</option>
                </select>
            </div>
            <button type="submit" class="admin-button admin-button-outline">Filtrar</button>
            @if($q || $status)
                <a href="{{ route('admin.clients.index') }}" class="admin-button admin-button-ghost">Limpiar</a>
            @endif
        </form>
    </div>

    @if($clients->isEmpty())
        <div class="admin-empty">
            <p>
                @if($q || $status)
                    Ninguna marca coincide con ese filtro.
                @else
                    Todavía no hay marcas registradas.
                @endif
            </p>
            <a href="{{ route('admin.clients.create') }}"
               class="admin-button admin-button-primary admin-button-sm"
               style="margin-top:1rem">
                Nueva marca
            </a>
        </div>
    @else
        <div class="admin-card admin-card-flush">
            <div class="admin-table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Marca</th>
                            <th>Industria</th>
                            <th>Contacto</th>
                            <th>Contexto</th>
                            <th>Estado</th>
                            <th><span class="screen-reader-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clients as $client)
                            <tr>
                                <td>
                                    {{-- A draft goes back to the screen it was
                                         being written on, not to the brand page:
                                         it has no process, no team and no files
                                         yet, and the only useful thing to do
                                         with it is carry on. --}}
                                    <a href="{{ $client->status->isDraft()
                                                ? route('admin.clients.create', ['borrador' => $client->slug])
                                                : route('admin.clients.show', $client) }}"
                                       class="admin-row" style="gap:.75rem">
                                        <span class="admin-monogram">{{ $client->initials() }}</span>
                                        <span>
                                            <b>{{ $client->name }}</b><br>
                                            <span class="admin-meta">
                                                @if($client->status->isDraft())
                                                    Sin terminar · continuar
                                                @else
                                                    /{{ $client->slug }}
                                                @endif
                                            </span>
                                        </span>
                                    </a>
                                </td>
                                <td>{{ $client->industry ?: '—' }}</td>
                                <td>
                                    @if($client->contact_name || $client->contact_email)
                                        {{ $client->contact_name }}<br>
                                        <span class="admin-meta">{{ $client->contact_email }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="admin-numbers">
                                    {{ $client->brand_assets_count }}
                                    {{ Str::plural('archivo', $client->brand_assets_count) }}
                                </td>
                                <td>
                                    @if($client->trashed())
                                        <span class="admin-badge">Archivada</span>

                                        @if(auth()->user()->isAdmin())
                                            <div class="admin-row" style="gap:.5rem;margin-top:.5rem">
                                                <form method="POST"
                                                      action="{{ route('admin.clients.restore', $client->slug) }}">
                                                    @csrf
                                                    <button type="submit"
                                                            class="admin-button admin-button-secondary admin-button-sm">
                                                        Recuperar
                                                    </button>
                                                </form>

                                                {{-- Permanent deletion lives only here, in the
                                                     papelera. Nothing is ever one click from
                                                     gone: archiving is always the first step. --}}
                                                <details class="admin-disclosure">
                                                    <summary class="admin-button admin-button-ghost admin-button-sm">
                                                        Eliminar
                                                    </summary>
                                                    <form method="POST"
                                                          action="{{ route('admin.clients.purge', $client->slug) }}"
                                                          class="admin-stack" style="margin-top:.5rem">
                                                        @csrf
                                                        @method('DELETE')
                                                        <p class="admin-hint">
                                                            Se van también sus entregables, archivos,
                                                            reuniones y las cuentas de su gente. No se
                                                            puede deshacer. Escribe
                                                            <b>{{ $client->name }}</b> para confirmar.
                                                        </p>
                                                        <input type="text" name="confirmation"
                                                               placeholder="{{ $client->name }}" required>
                                                        <button type="submit"
                                                                class="admin-button admin-button-ghost admin-button-sm">
                                                            Eliminar para siempre
                                                        </button>
                                                    </form>
                                                </details>
                                            </div>
                                        @endif
                                    @else
                                        <span class="admin-badge {{ $client->status->badgeClass() }}">
                                            {{ $client->status->label() }}
                                        </span>
                                    @endif
                                </td>

                                {{-- Removing a brand from the row itself. It used
                                     to live only on the brand page, which left a
                                     draft with no way out at all: its row links
                                     to the screen it was being written on, and
                                     that screen had no delete. --}}
                                <td>
                                    @if($client->trashed())
                                        {{-- Recuperar and Eliminar are in the estado
                                             column already; nothing to add here. --}}
                                    @elseif($client->status->isDraft())
                                        <details class="admin-disclosure">
                                            <summary class="admin-button admin-button-ghost admin-button-sm">
                                                Descartar
                                            </summary>
                                            <form method="POST"
                                                  action="{{ route('admin.clients.draft.discard', $client) }}"
                                                  class="admin-stack" style="margin-top:.5rem">
                                                @csrf
                                                @method('DELETE')
                                                <p class="admin-hint">
                                                    @php $written = $client->deliverablesOrNew()->filledCount(); @endphp
                                                    @if($written > 0)
                                                        Tiene {{ $written }}
                                                        {{ Str::plural('entregable', $written) }} escrito{{ $written === 1 ? '' : 's' }}.
                                                    @endif
                                                    Un borrador no va a la papelera: se borra.
                                                </p>
                                                <button type="submit"
                                                        class="admin-button admin-button-ghost admin-button-sm">
                                                    Sí, descartar
                                                </button>
                                            </form>
                                        </details>
                                    @elseif(auth()->user()->isAdmin())
                                        <a href="{{ route('admin.clients.show', $client) }}#archivar"
                                           class="admin-button admin-button-ghost admin-button-sm">
                                            Archivar
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{ $clients->links() }}
    @endif

</x-layouts.app>
