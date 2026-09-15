{{--
    Homepage remake — navbar (in the layout) + hero section only.
--}}
<x-layouts.site-remake css="home-remake">

    <section class="home-hero">

        <div class="container">

            <div class="home-hero-text">
                <h1 class="home-hero-headline">
                    Estamos aquí para ayudar a tu marca a tomar las decisiones correctas
                </h1>

                <p class="home-hero-paragraph">
                    Sí, vivimos en un mundo que va muy rápido y las decisiones que tu marca debe
                    tomar a veces pueden verse presionadas. O peor: sentirse presionadas. Y si es
                    que ves demasiados caminos para la tuya o simplemente no encuentras uno que la
                    conecte con las personas correctas, pues debes parar. Debes pensar y crear.
                    Crear de verdad.
                </p>

                <a href="{{ route('nosotros') }}" class="button">Más sobre Breakfast Aquí</a>
            </div>

            <figure class="home-hero-photo">
                <img src="{{ asset('img/home-remake/hero.webp') }}"
                     alt="Bandeja de desayuno en la cama, vista desde arriba: panqueques con arándanos, huevos, tocino y pan tostado sobre lino blanco"
                     fetchpriority="high">
            </figure>

        </div>

        <div class="home-scrolling-band" aria-label="Procesos Creativos">
            <div class="home-scrolling-band-track">
                @foreach (range(1, 2) as $pass)
                    <span class="home-scrolling-band-group" @if($pass === 2) aria-hidden="true" @endif>
                        @foreach (range(1, 3) as $i)
                            <span>Procesos Creativos</span>
                        @endforeach
                    </span>
                @endforeach
            </div>
        </div>

    </section>


    <section class="home-letter">
        <div class="container">
        <figure class="home-letter-photo">
            <img src="{{ asset('img/home-remake/bg3.webp') }}"
                 alt="Mesa de café en la vereda con un periódico, un croissant y un espresso"
                 loading="lazy">
        </figure>

        <div class="home-letter-text">
            <div class="container">
                <h2 class="home-letter-heading">Carta a quien necesite crear</h2>

                <p class="home-letter-paragraph">
                    Que la creatividad les haga conectar con eso que no encontraban. Que se dejen
                    ser niños. Que se dejen jugar, crear e idear. Que sientan conexión con sus
                    ideas como la primera vez. Que conversen de lo incómodo. Que se enfrenten a sus
                    “lo hago luego”. Que miren a su potencial a los ojos y lo conozcan desde el
                    alma. Que utilicen sus manos, sus mentes. Que utilicen su alma al ponerla en
                    cada promesa de ejecución.
                </p>

                <a href="{{ url('/carta') }}" class="button">Seguir Leyendo</a>
            </div>
        </div>
</div>
    </section>


    <section class="home-services">
        <div class="container">

            <figure class="home-services-photo">
                <img src="{{ asset('img/home-remake/bg4.webp') }}"
                     alt="Mesa de café azul con un croissant, una taza y un libro junto a un abrigo colgado"
                     loading="lazy">
            </figure>

            <div class="home-services-text">
                <div class="container">
                    <h2 class="home-services-heading">Lo que hacemos:</h2>

                    <p class="home-services-paragraph">
                        Trabajamos desde la marca en distintos puntos del negocio. Somos una
                        consultora de procesos creativos: talleres y consultorías hechas a
                        medida para solucionar problemas.
                    </p>

                    <p class="home-services-paragraph">
                        Creamos marcas, diseñamos servicios y proponemos nuevos productos para
                        que los negocios puedan siempre cumplir la promesa que les hacen a sus
                        clientes.
                    </p>

                    <a href="{{ route('servicios') }}" class="button">Más sobre nuestros servicios</a>
                </div>
            </div>

        </div>
    </section>

</x-layouts.site-remake>
