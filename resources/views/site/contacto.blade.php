{{--
    Contacto — the introductory-session request.
    Copy and field list are from vamosdebreakfast.com/contacto.
--}}
<x-layouts.site-remake css="contacto"
    title="Contacto"
    description="Agenda una sesión virtual de introducción. Deja tus datos y nuestro equipo se contactará contigo.">

    <article class="contacto" data-plane>
        <div class="container">

          <h1 class="contacto-heading" data-typewriter>
              Agenda tu sesión introductoria
          </h1>

          <div class="contacto-body" data-fade-in>

            <p class="contacto-intro">
                Creemos en los procesos creativos como un método transformador para líderes,
                marcas y negocios. Agenda una sesión virtual de introducción para entender más
                sobre lo que hacemos pero sobre todo, para contarnos todo lo que puedes hacer.
                <strong>Deja tus datos y nuestro equipo se contactará contigo.</strong>
            </p>

            @if (session('status'))
                <p class="contacto-status" role="status">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ route('contacto.store') }}" class="contacto-form">
                @csrf

                <fieldset class="contacto-fieldset">
                    <legend class="contacto-legend">Nombre y apellido</legend>

                    <div class="contacto-field-pair">
                        <div class="contacto-field">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name"
                                   value="{{ old('first_name') }}"
                                   autocomplete="given-name" required>
                            @error('first_name')
                                <p class="contacto-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="contacto-field">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name"
                                   value="{{ old('last_name') }}"
                                   autocomplete="family-name" required>
                            @error('last_name')
                                <p class="contacto-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </fieldset>

                <div class="contacto-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="{{ old('email') }}"
                           autocomplete="email" required>
                    @error('email')
                        <p class="contacto-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="contacto-field">
                    <label for="phone">Whatsapp o Teléfono</label>
                    <input type="tel" id="phone" name="phone"
                           value="{{ old('phone') }}"
                           autocomplete="tel" required>
                    @error('phone')
                        <p class="contacto-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="contacto-field">
                    <label for="about_brand">Un poco sobre tu marca</label>
                    <textarea id="about_brand" name="about_brand" rows="6"
                              aria-describedby="about_brand-hint">{{ old('about_brand') }}</textarea>
                    <p id="about_brand-hint" class="contacto-hint">
                        Cuéntanos lo que consideres importante sobre tu proyecto y tus ideas.
                    </p>
                    @error('about_brand')
                        <p class="contacto-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Honeypot. Hidden from people and from screen readers; only a
                     bot filling every field it finds will put anything here. --}}
                <div class="contacto-honeypot" aria-hidden="true">
                    <label for="website">No llenar</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" class="button">Quiero mi sesión</button>
            </form>

          </div>

          <figure class="contacto-photo" data-fade-in>
              <img src="{{ asset('img/contacto/mesa-cafe.webp') }}"
                   alt="Mesa de café vista desde arriba con dos croissants, un espresso y un vaso de agua"
                   loading="lazy">
          </figure>

        </div>
    </article>

</x-layouts.site-remake>
