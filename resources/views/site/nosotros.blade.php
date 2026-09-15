{{--
    Nosotros — a letter. Copy is verbatim from vamosdebreakfast.com/nosotros.
--}}
<x-layouts.site-remake css="nosotros"
    title="Nosotros"
    description="Cada marca requiere de una pausa, de procesos creativos que le ayuden a encontrar el espacio que está buscando.">

    <article class="nosotros" data-plane>
        <div class="container">

          <h1 class="nosotros-heading" data-typewriter>
              Estamos aquí para ayudar a tu marca a tomar las decisiones correctas:
          </h1>

          <div class="nosotros-letter" data-fade-in>

            <div class="nosotros-text">
                <p>
                    Sí, es cliché decir que el mundo cambia y que todos los días amanece
                    diferente a lo que fue ayer. Pero hay frases cliché que funcionan por algo.
                    Y saber que el futuro es hoy y que los caminos creativos, estratégicos y de
                    negocio cada vez se multiplican es lo que te hará ir siempre a la delantera.
                    Es imposible frenar al mundo. Pero sí es posible saber surfearlo.
                </p>

                <p>
                    Si estás en esta página y sigues leyendo, probablemente sea porque sientes
                    que tienes la tabla de surfear pero no sabes utilizarla, o tal vez estás
                    creando tu tabla para posicionar lo que en verdad sabes hacer. A qué me
                    refiero. Creo que los negocios son el instrumento perfecto para crear
                    soluciones reales a la vida de las personas. Y son una buena tabla para
                    surfear el mundo. Son una excelente manera de transitar esta experiencia
                    humana que nos trae a todos a preguntarnos cosas, a mirar caminos y a veces
                    a perdernos.
                </p>

                <p>
                    No quiero decir que perdernos es malo. Muchas veces nos ayuda a encontrar el
                    camino correcto. Pero, qué pasa cuando hay muchísimos caminos. Qué pasa
                    cuando la información que consumimos cada vez es más y muchas veces se
                    contradice. Pues no hay otra solución que volver al origen. Volver a casa a
                    tomar nuestro desayuno favorito.
                </p>

                <p>
                    Sí, vivimos en un mundo que va muy rápido y las decisiones que tu marca debe
                    tomar a veces pueden verse presionadas. O peor: sentirse presionadas. Y si
                    es que ves demasiados caminos para la tuya o simplemente no encuentras uno
                    que la conecte con las personas correctas, pues debes parar. Debes pensar y
                    crear. Crear de verdad.
                </p>

                <p>
                    Y la buena noticia es que no estás solo/a en esto. (Aquí es cuando aprovecho
                    este texto para promocionar nuestros servicios). Porque te prometo, cada
                    marca es diferente, cada marca requiere de una pausa, de procesos creativos
                    que le ayudan a encontrar ese espacio que está buscando. Ese espacio que le
                    está esperando para crecer juntos. Para hablar a las personas correctas.
                    Para llenar de alma, desde la esencia, el discurso que se enviará por todos
                    sus medios. Si estás aquí es porque sientes que puedes hacer más. Que tu
                    negocio y tu marca están aquí para posicionar una idea, un concepto que
                    salió de lo más profundo de tu alma (o de tu jefe si es que trabajas en Mkt
                    y estas aquí buscando servicios).
                </p>

                <p>
                    Anímate a verle a tu idea a los ojos. Anímate a encontrar espacios en la
                    sociedad que no están utilizados. Anímate a crear un lenguaje para que tu
                    marca hable como ella sola. Anímate a accionar diferente. Anímate a ser lo
                    que los grandes se propusieron ser al comienzo de sus caminos, o eligiendo
                    nuevos. Anímate a hacerlo, anímate a serlo. Anímate a ser ese creativo que
                    tu niño busca, el que tu interior grita. Hazlo. Hazlo hoy. Hazlo siempre. Y
                    si necesitas compañía. Sabes donde encontrarnos :)
                </p>
            </div>

            <p class="nosotros-signature">
                Siempre para servir.<br>
                Pablo y todo el equipo Breakfast.
            </p>

            <div class="nosotros-close">
                <p class="nosotros-close-text">
                    Si tu marca está lista para dar un
                    <em class="nosotros-circled">nuevo paso<svg
                            viewBox="0 0 200 60" preserveAspectRatio="none" aria-hidden="true">
                            <path d="M20,44 C6,30 14,14 44,9 C78,3 130,4 164,10 C190,15 197,29 186,40 C174,52 128,57 88,56 C52,55 22,50 12,38 C8,33 9,27 14,22"/>
                        </svg></em>
                    <em class="nosotros-underline-wavy">o quieres crear tu nueva marca</em>,
                    es momento de
                    <em class="nosotros-underline-straight">ponernos en contacto</em>.
                </p>

                <a href="{{ route('contacto') }}" class="button">Agenda tu sesión gratuita</a>
            </div>

          </div>

          <figure class="nosotros-photo" data-fade-in>
              <img src="{{ asset('img/nosotros/mesa-desayuno.webp') }}"
                   alt="Mesa larga vestida de mantel blanco, servida con pan, fruta, café y jugos"
                   loading="lazy">
          </figure>

        </div>
    </article>

</x-layouts.site-remake>
