{{--
    Carta — the letter linked from the home page's "Carta a quien necesite crear"
    section. Copy is from vamosdebreakfast.com/carta.

    INCOMPLETE: only the opening paragraph and the closing line could be
    recovered. The body of the letter is missing — see the note inside
    .carta-text. Nothing here is written by anyone but Breakfast.
--}}
<x-layouts.site-remake css="carta"
    title="Carta"
    description="Carta a quien necesite crear. Que la creatividad les haga conectar con eso que no encontraban.">

    <article class="carta" data-plane>
        <div class="container">

          <h1 class="carta-heading" data-typewriter>
              Carta a quien necesite crear:
          </h1>

          <div class="carta-letter" data-fade-in>

            <div class="carta-text">
                <p>
                    Que la creatividad les haga conectar con eso que no encontraban. Que se
                    dejen ser niños. Que se dejen jugar, crear e idear. Que sientan conexión
                    con sus ideas como la primera vez. Que conversen de lo incómodo. Que se
                    enfrenten a sus “lo hago luego”. Que miren a su potencial a los ojos y lo
                    conozcan desde el alma. Que utilicen sus manos, sus mentes. Que utilicen su
                    alma al ponerla en cada promesa de ejecución.
                </p>

                {{-- MISSING: the middle of the letter goes here. --}}

                <p>
                    Porque ¿qué van a hacer el momento que otra idea cierre la puerta?
                </p>
            </div>

            <div class="carta-close">
                <p class="carta-close-text">
                    Si quieres saber más de nosotros:
                </p>

                <a href="/contacto" class="button">Contáctanos</a>
            </div>

          </div>

          <figure class="carta-photo" data-fade-in>
              <img src="{{ asset('img/carta/mesa-vereda.webp') }}"
                   alt="Mesa de café en la vereda con un periódico, un croissant y un espresso"
                   loading="lazy">
          </figure>

        </div>
    </article>

</x-layouts.site-remake>
