import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { TextPlugin } from 'gsap/TextPlugin';

gsap.registerPlugin(ScrollTrigger, TextPlugin);

/* Everything animated lives inside this matchMedia block, so it is set up only
   when the visitor has not asked for reduced motion — and GSAP reverts it
   cleanly if they change that setting mid-session. */
gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', () => {

    /* ----------------------------------------------------------------------
       LOAD SEQUENCE

       One timeline rather than separate delays, so the steps hold their
       relationship when a duration changes. Positions below are measured from
       the moment the yellow planes finish, which is what PLANES stands for.
       ---------------------------------------------------------------------- */

    const PLANES = 0.8;           // fade of the navbar + hero backgrounds

    const navbarItems = document.querySelectorAll('.navbar-logo, .navbar-links a');
    const photoFrame = document.querySelector('.home-hero-photo');
    const heroParagraph = document.querySelector('.home-hero-paragraph');
    const heroButton = document.querySelector('.home-hero-text .button');
    const headline = document.querySelector('.home-hero-headline');

    // Screen readers get the whole sentence, never a half-typed fragment.
    const typed = headline?.textContent.trim();
    if (headline) headline.setAttribute('aria-label', typed);

    /* Waiting on the fonts stops the type reflowing mid-animation when the
       webfont lands. It resolves even if a font fails, so this cannot strand
       the page. */
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => {

        const load = gsap.timeline({ defaults: { ease: 'power2.out' } });

        /* The yellow surfaces, together, before anything on them. On the home
           page that is the hero; on the interior pages it is the card marked
           data-plane. */
        load.fromTo('.navbar, .home-hero, [data-plane]',
            { autoAlpha: 0 },
            { autoAlpha: 1, duration: PLANES },
            0
        );

        // Logo first, then the links left to right (document order).
        if (navbarItems.length) {
            load.fromTo(navbarItems,
                { x: -32, autoAlpha: 0 },
                { x: 0, autoAlpha: 1, duration: 1.5, stagger: 0.3 },
                PLANES
            );
        }

        /* Grows and fades. Targets the FRAME — the parallax further down owns
           the <img> transform, and two tweens cannot share one. */
        if (photoFrame) {
            load.fromTo(photoFrame,
                { scale: 0.9, autoAlpha: 0 },
                { scale: 1, autoAlpha: 1, duration: 2 },
                PLANES + 1
            );
        }

        // Typewriter. Linear, because an ease makes typing stutter.
        if (headline) {
            headline.textContent = '';

            load.to(headline, {
                text: typed,
                duration: 2.2,
                ease: 'none',
                onStart() {
                    gsap.set(headline, { autoAlpha: 1 });
                    headline.classList.add('is-typing');
                },
                onComplete() {
                    headline.classList.remove('is-typing');
                },
            }, PLANES + 1.5);
        }

        /* Paragraph and button together, in one tween rather than two placed at
           the same time — they cannot drift apart if a duration changes. */
        const heroCopy = [heroParagraph, heroButton].filter(Boolean);

        if (heroCopy.length) {
            load.fromTo(heroCopy,
                { x: -32, autoAlpha: 0 },
                { x: 0, autoAlpha: 1, duration: 1.5 },
                PLANES + 1.7
            );
        }

        /* ------------------------------------------------------------------
           INTERIOR PAGES

           Marked with data-typewriter / data-fade-in rather than named class
           by class, so a new page joins the sequence by adding the attribute
           and app.js never needs editing. On the home page these match
           nothing and the block is skipped.
           ------------------------------------------------------------------ */

        const pageTitle = document.querySelector('[data-typewriter]');
        const fadeIns = document.querySelectorAll('[data-fade-in]');

        if (pageTitle) {
            const title = pageTitle.textContent.trim();

            // Screen readers get the whole heading, never a half-typed one.
            pageTitle.setAttribute('aria-label', title);
            pageTitle.textContent = '';

            load.to(pageTitle, {
                text: title,
                duration: 1.8,
                ease: 'none',        // an ease makes typing stutter
                onStart() {
                    gsap.set(pageTitle, { autoAlpha: 1 });
                    pageTitle.classList.add('is-typing');
                },
                onComplete() {
                    pageTitle.classList.remove('is-typing');
                },
            }, PLANES + 0.6);
        }

        // Copy first, then the photograph — document order.
        if (fadeIns.length) {
            load.fromTo(fadeIns,
                { autoAlpha: 0 },
                { autoAlpha: 1, duration: 1.2, stagger: 0.25 },
                PLANES + 1.9
            );
        }
    });

    /* ----------------------------------------------------------------------
       SCROLL
       ---------------------------------------------------------------------- */

    /* Hero photograph drifts down and eases out of its zoom as the hero
       scrolls past. Amounts are set in CSS on .home-hero, so the tuning stays
       in the stylesheet. */
    const hero = document.querySelector('.home-hero');
    const photo = hero?.querySelector('.home-hero-photo img');

    if (photo) {
        const styles = getComputedStyle(hero);

        gsap.fromTo(photo,
            { y: 0, scale: parseFloat(styles.getPropertyValue('--home-hero-photo-zoom')) || 1 },
            {
                y: parseFloat(styles.getPropertyValue('--home-hero-photo-travel')) || 0,
                scale: 1,
                ease: 'none',
                scrollTrigger: {
                    trigger: hero,
                    start: 'top top',
                    end: 'bottom top',
                    scrub: true,
                },
            }
        );
    }

    /* Carta and Lo que hacemos: photo and copy fade in, staggered. No
       movement. Each section triggers on its own scroll position. */
    /* Interior-page sections that arrive on scroll rather than on load. Same
       treatment as the home page's two below: contents fade in, staggered, no
       movement. */
    document.querySelectorAll('[data-fade-on-scroll]').forEach((section) => {
        gsap.from(section.children, {
            autoAlpha: 0,
            duration: 0.8,
            ease: 'power2.out',
            stagger: 0.12,
            scrollTrigger: { trigger: section, start: 'top 80%' },
        });
    });

    document.querySelectorAll('.home-letter, .home-services').forEach((section) => {
        gsap.from(section.querySelectorAll('figure, h2, p, .button'), {
            autoAlpha: 0,
            duration: 0.8,
            ease: 'power2.out',
            stagger: 0.12,
            scrollTrigger: { trigger: section, start: 'top 75%' },
        });
    });
});
