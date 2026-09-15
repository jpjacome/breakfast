{{--
    Servicios — what Breakfast does, then the FAQ.
    Copy is verbatim from vamosdebreakfast.com/servicios.

    INCOMPLETE: the third section's copy is still missing — see the note above
    it. Nothing on this page is written by anyone but Breakfast.
--}}
<x-layouts.site-remake css="servicios"
    title="Servicios"
    description="Somos una consultora de procesos creativos: talleres y consultorías hechas a medida para solucionar problemas.">

    <article class="servicios" data-plane>
        <div class="container">

            {{-- Section one. Not scroll-triggered like the two below it: this one
                 is on screen at load, so it belongs to the load sequence. --}}
            <section class="servicios-section">
                <div class="servicios-section-text">
                    <h1 class="servicios-heading" data-typewriter>A tu servicio.</h1>

                    <p class="servicios-lead" data-fade-in>
                        Trabajamos desde la marca en distintos puntos del negocio. Somos una
                        consultora de procesos creativos: talleres y consultorías hechas a
                        medida para solucionar problemas. <strong>Creamos marcas, diseñamos
                        servicios y proponemos nuevos productos</strong> para que los negocios
                        puedan siempre cumplir la promesa que les hacen a sus clientes.
                    </p>
                </div>

                <figure class="servicios-section-photo" data-fade-in>
                    <img src="{{ asset('img/servicios/mesa-ventana.webp') }}"
                         alt="Mesa de café junto a una ventana con dos cortados, un periódico y bollería">
                </figure>
            </section>

            {{-- Reversed: photograph on the left, copy on the right. --}}
            <section class="servicios-section servicios-section-reversed" data-fade-on-scroll>
                <div class="servicios-section-text">
                    <h2 class="servicios-section-heading">Trabajamos desde tu marca</h2>

                    <p>
                        La marca es un todo, imaginemos que es quien transmite la idea del
                        negocio a través de 6 pilares estratégicos a las personas que se
                        encuentran con ella en su camino. A sus clientes o posibles clientes, a
                        sus colaboradores, proveedores, competencia y a la sociedad en general.
                    </p>

                    <p>
                        Es quien engloba al negocio, a una promesa y a una manera de hacer las
                        cosas. Y sí, como lo dicen los expertos, una marca bien trabajada es el
                        bien más importante. No solamente por cuánto llega a costar en la bolsa
                        o en un mercado, sino por el impacto cultural que esta tiene.
                    </p>
                </div>

                <figure class="servicios-section-photo">
                    <img src="{{ asset('img/servicios/bandeja-periodico.webp') }}"
                         alt="Bandeja de plata con café, huevos pasados por agua y un periódico sobre mantel blanco"
                         loading="lazy">
                </figure>
            </section>

            {{-- Third section: the FAQ, with the last photograph beside it. --}}
            <section class="servicios-section servicios-faq" data-fade-on-scroll>
                <div class="servicios-section-text">
                <h2 class="servicios-faq-heading">Preguntas frecuentes</h2>

                @php
                    $faqs = [
                        [
                            'q' => '¿Cómo saber si necesito de Breakfast?',
                            'a' => 'Nos enfocamos en crear sesiones y talleres personalizados para re significar marcas, crear buenos servicios o idear productos. Si tu negocio está tomando un nuevo camino o quiere buscar uno nuevo mediante la diferenciación o el trabajar con un nuevo público, contáctanos.',
                        ],
                        [
                            'q' => '¿Cómo son sus procesos creativos?',
                            'a' => 'Nuestros procesos buscan solucionar problemas de marca, negocio y liderazgo basándonos en técnicas sociológicas, psicológicas y de estrategia aplicadas al design thinking. En pocas palabras, son talleres y sesiones de consultoría para aplicar la creatividad al impacto de tu marca o negocio.',
                        ],
                        [
                            'q' => '¿Hacen Branding?',
                            'a' => 'Sí, hemos creado más de 100 marcas y rebrandeado más de 50, el branding es un servicio donde buscamos la transversalidad de la marca en no solamente el negocio sino en todos los públicos a los que esta se dirija.',
                        ],
                        [
                            'q' => '¿Qué más hacen además de Branding?',
                            'a' => 'Trabajamos en procesos de diseño de servicio, diseño de producto y estrategias de comunicación para las marcas que quieran tomar un nuevo camino a través de un rebranding o el lanzamiento de un nuevo producto o servicio.',
                        ],
                        [
                            'q' => '¿Qué es el diseño de servicio?',
                            'a' => 'El diseño de servicio se trata de planear la experiencia completa de un cliente, no solo el producto final. Es como coreografiar una danza: te aseguras de que cada paso (desde que el cliente descubre tu servicio hasta que lo usa y da su opinión) sea fluido, fácil y memorable. El objetivo es que la interacción sea tan buena que el cliente quiera repetir su interacción con la marca las veces que sea posible.',
                        ],
                    ];
                @endphp

                <div class="servicios-faq-list">
                    @foreach ($faqs as $faq)
                        {{-- <details> is the browser's own disclosure widget: it opens on
                             click, is keyboard operable and announced correctly, and needs
                             no JavaScript to work. --}}
                        <details class="servicios-faq-item">
                            <summary class="servicios-faq-question">
                                {{ $faq['q'] }}
                                <span class="servicios-faq-marker" aria-hidden="true"></span>
                            </summary>

                            <div class="servicios-faq-answer">
                                <p>{{ $faq['a'] }}</p>
                            </div>
                        </details>
                    @endforeach
                </div>
                </div>

                <figure class="servicios-section-photo">
                    <img src="{{ asset('img/servicios/desayuno-panques.webp') }}"
                         alt="Desayuno servido con panqueques, moras, pan tostado y café"
                         loading="lazy">
                </figure>
            </section>

            {{-- Two identical passes, the second hidden from screen readers: the
                 track scrolls exactly one pass then snaps back, so the loop has
                 no seam. Same construction as the home page's band. --}}
            <div class="servicios-band" aria-label="Agenda tu Sesión">
                <div class="servicios-band-track">
                    @foreach (range(1, 2) as $pass)
                        <span class="servicios-band-group" @if($pass === 2) aria-hidden="true" @endif>
                            @foreach (range(1, 3) as $i)
                                <span>Agenda tu Sesión</span>
                            @endforeach
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="servicios-close" data-fade-on-scroll>
                <p class="servicios-close-text">Si quieres saber más de nosotros:</p>

                <a href="{{ route('contacto') }}" class="button">Contáctanos</a>
            </div>

        </div>
    </article>

</x-layouts.site-remake>
