{{--
    /legal/privacidad and /legal/terminos, both still to be written.

    On the site layout rather than a page of its own: these are linked from the
    public footer and from the login screen, so they should carry the same
    navbar and footer as everything else a visitor can reach.
--}}
<x-layouts.site-remake css="login" title="Legal" :noindex="true">

    <article class="legal" data-plane>
        <div class="container">
            <h1 class="legal-heading" data-typewriter>Legal</h1>

            <p class="legal-text" data-fade-in>
                Esta página está pendiente de redacción. Mientras tanto, si tienes
                dudas sobre cómo tratamos tus datos, escríbenos a
                <a href="mailto:info@vamosdebreakfast.com">info@vamosdebreakfast.com</a>.
            </p>
        </div>
    </article>

</x-layouts.site-remake>
