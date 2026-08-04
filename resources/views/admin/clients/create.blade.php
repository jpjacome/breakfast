<x-layouts.app title="Nueva marca" heading="nueva marca">

    <div style="max-width:640px;">

        <div class="page-head">
            <div>
                <p class="bkf-eyebrow">Clientes</p>
                <h2 class="bkf-h1" style="margin-top:var(--space-2);">Nueva marca</h2>
                <p class="bkf-lead" style="margin-top:var(--space-3);max-width:48ch;">
                    Solo lo esencial. El resto —estrategia, etapas, usuarios— se completa después.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.clients.store') }}" novalidate>
            @csrf

            <div class="bkf-card">
                <div class="auth-form__fields">

                    <div class="bkf-field">
                        <label class="bkf-label" for="name">Nombre de la marca</label>
                        <input class="bkf-input" id="name" name="name" type="text"
                               value="{{ old('name') }}" placeholder="The Coffee Club"
                               @error('name') aria-invalid="true" @enderror required autofocus>
                        <span class="bkf-hint">La URL se genera sola a partir del nombre.</span>
                    </div>

                    <div class="bkf-field">
                        <label class="bkf-label" for="industry">Industria</label>
                        <input class="bkf-input" id="industry" name="industry" type="text"
                               value="{{ old('industry') }}" placeholder="Café de especialidad">
                    </div>

                    <div class="bkf-field">
                        <label class="bkf-label" for="status">Estado</label>
                        <select class="bkf-select" id="status" name="status" required>
                            @foreach($statuses as $case)
                                <option value="{{ $case->value }}" @selected(old('status', 'activo') === $case->value)>
                                    {{ $case->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <hr class="bkf-divider">

                    <div class="bkf-field">
                        <label class="bkf-label" for="contact_name">Nombre de contacto</label>
                        <input class="bkf-input" id="contact_name" name="contact_name" type="text"
                               value="{{ old('contact_name') }}" placeholder="María García">
                    </div>

                    <div class="bkf-field">
                        <label class="bkf-label" for="contact_email">Correo de contacto</label>
                        <input class="bkf-input" id="contact_email" name="contact_email" type="email"
                               value="{{ old('contact_email') }}" placeholder="maria@thecoffeeclub.com"
                               @error('contact_email') aria-invalid="true" @enderror>
                    </div>

                    <div class="bkf-field">
                        <label class="bkf-label" for="notes">Notas internas</label>
                        <textarea class="bkf-textarea" id="notes" name="notes"
                                  placeholder="Contexto para el equipo. El cliente nunca ve esto.">{{ old('notes') }}</textarea>
                    </div>

                </div>
            </div>

            <div class="bkf-row" style="margin-top:var(--space-5);gap:var(--space-3);">
                <button type="submit" class="bkf-btn bkf-btn--primary bkf-btn--lg">Crear marca</button>
                <a href="{{ route('admin.clients.index') }}" class="bkf-btn bkf-btn--ghost">Cancelar</a>
            </div>

        </form>
    </div>

</x-layouts.app>
