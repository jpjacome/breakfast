import { explain, explainNetwork } from './assistant-error.js';

/**
 * The Brand Egg: hover, focus and select one of the five rings.
 *
 * ⚠️ THE RING IS THE CONTROL. Each <g> carries tabindex="0", role="button" and
 * its layer's name in the component, and hover and focus do the SAME thing on
 * purpose — a ring that only answers a mouse is not a control, and the keyboard
 * path here is not an afterthought bolted on later.
 *
 * ⚠️ A LAYER APPEARS THREE TIMES AND NOTHING BUT THIS FILE SAYS SO. A band in
 * the drawing, a button in the row above it, a card in the scrolling column —
 * and the pointer on any one of them lights all three. Without that the
 * drawing is decoration sitting next to the real content, which is the one
 * thing it must not be.
 *
 * ⚠️ THERE IS NO READOUT UNDER THE EGG, AND THIS FILE NO LONGER WRITES ONE.
 * There was a panel that named the hovered ring and listed its entregables,
 * and every word of it was already on that layer's card. What replaced it is
 * cheaper and says more: the ring lights its card, and clicking the ring
 * brings that card into view in the scrolling column. The drawing is a way of
 * NAVIGATING the five layers rather than a second place they are described.
 *
 * This file therefore invents no content and needs to know nothing about what
 * a layer is — it matches data-layer on both sides and moves classes. Same
 * instinct as the enum owning its own sources: one place decides.
 */

for (const egg of document.querySelectorAll('[data-brand-egg]')) {
    const rings = [...egg.querySelectorAll('[data-layer]')];

    if (rings.length === 0) {
        continue;
    }

    /*
     * Everything ELSE on the page that stands for a layer: the five cards, and
     * the five buttons in the row above.
     *
     * ⚠️ MATCHED BY data-layer, NOT BY A LIST OF KNOWN CLASSES. A layer now
     * appears three times on the admin screen and the count is not obviously
     * final, so the rule is "carries data-layer, is not part of the drawing"
     * and a fourth face would need no change here at all. It is also why the
     * client's read-only view needs no branch: it renders the egg alone, this
     * list comes back empty, and every loop below runs zero times.
     */
    const echoes = [...document.querySelectorAll('[data-layer]')]
        .filter((el) => !egg.contains(el));

    const cardFor = (layer) =>
        document.querySelector(`[data-brand-egg-card][data-layer="${CSS.escape(layer)}"]`);

    /**
     * Light one layer wherever it appears, or clear the lighting entirely.
     *
     * The class on the <svg> is what lets the OTHER four rings step back: a
     * sibling selector cannot see which one is hovered, so the parent carries
     * "one of my rings is lit" and the rings answer that.
     *
     * @param {?string} layer the layer's value, or null to clear
     */
    function light(layer) {
        egg.classList.toggle('has-lit', layer !== null);

        for (const el of [...rings, ...echoes]) {
            el.classList.toggle('is-linked', el.dataset.layer === layer);
        }
    }

    /**
     * Select a layer: leave it lit, and bring its card into view.
     *
     * block: 'nearest' so a card already on screen does not jump under the
     * reader — selecting is meant to answer "where is this one?", and moving
     * something that was already in front of them answers a question nobody
     * asked. The column is the scroller (the page cannot scroll on this
     * screen), so this scrolls the column and the egg does not move.
     *
     * @param {string} layer the layer's value
     */
    function select(layer) {
        for (const el of [...rings, ...echoes]) {
            el.classList.toggle('is-open', el.dataset.layer === layer);
        }

        // Only the CARD is scrolled to. The row above is always in view, so
        // scrolling it would move something the reader can already see.
        cardFor(layer)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function clearSelection() {
        for (const el of [...rings, ...echoes]) {
            el.classList.remove('is-open');
        }
    }

    for (const ring of rings) {
        // tabindex and role are set in the component: a <g> is not a button,
        // so it has to be told that it is one.
        ring.addEventListener('mouseenter', () => light(ring.dataset.layer));
        ring.addEventListener('focus', () => light(ring.dataset.layer));

        ring.addEventListener('mouseleave', () => light(null));
        ring.addEventListener('blur', () => light(null));

        ring.addEventListener('click', () => select(ring.dataset.layer));

        // Enter and Space are what a control answers to when it is not really
        // a <button>. Both, because a keyboard user will try both.
        ring.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                select(ring.dataset.layer);
            }
        });
    }

    for (const el of echoes) {
        el.addEventListener('mouseenter', () => light(el.dataset.layer));
        el.addEventListener('mouseleave', () => light(null));

        /*
         * A card is not a control — it lights, and that is all.
         *
         * ⚠️ THE ROW OF BUTTONS THIS ALSO SERVED IS GONE (2026-09-17). It named
         * the five layers above the drawing and every word of it was already on
         * the cards; on a screen that is now two fixed rows it was height taken
         * out of the egg. The hook is kept rather than the loop simplified,
         * because anything carrying data-brand-egg-nav still selects — which is
         * how a second face of a layer arrives without touching this file.
         */
        if (el.matches('[data-brand-egg-nav]')) {
            el.addEventListener('click', () => select(el.dataset.layer));
        }
    }

    // Clicking away from the drawing and from everything that echoes it drops
    // the selection, rather than leaving a layer lit that nobody is looking at
    // any more.
    document.addEventListener('click', (event) => {
        const inside = egg.contains(event.target)
            || echoes.some((el) => el.contains(event.target));

        if (!inside) {
            clearSelection();
        }
    });
}

/* ==========================================================================
   Composing

   ⚠️ SEPARATE FROM EVERYTHING ABOVE, AND IT MAY FIND NOTHING. The hover and
   select code runs on both shells — the admin's screen and the client's
   read-only egg — because a ring is a control in both. Composing is Breakfast's
   alone, so this half simply finds no buttons on the portal and does nothing.
   That is why it is a second pass over the document rather than a branch inside
   the first.

   ⚠️ fetch(), NOT A FORM POST, and the reason is the host. A run is one call of
   ~20s or five of them chained, and a form post would be a frozen page for a
   hundred seconds with no way to say which ring it is on — and no way to tell
   a 429 from the concurrency gate (two AI turns account-wide) apart from a
   provider outage. Both need different words.
   ========================================================================== */

const composeButtons = [...document.querySelectorAll('[data-brand-egg-compose]')];

if (composeButtons.length > 0) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const endpoint = document.querySelector('[data-brand-egg-endpoint]')?.dataset.brandEggEndpoint;
    const report = document.querySelector('[data-brand-egg-report]');

    /** Say something in the report line under the drawing. */
    function say(message) {
        if (!report) {
            return;
        }

        report.textContent = message;
        report.hidden = message === '';
    }

    /**
     * Put a layer's text on its card, and light its ring.
     *
     * The card's paragraph carries data-brand-egg-text whether it holds a
     * synthesis or the sentence explaining why there is none, so replacing it
     * is one write either way — no branch on which of the two is showing.
     */
    function paint(layer, text) {
        const card = document.querySelector(
            `[data-brand-egg-card][data-layer="${CSS.escape(layer)}"]`,
        );

        if (!card) {
            return;
        }

        const paragraph = card.querySelector('[data-brand-egg-text]');

        if (paragraph && text) {
            paragraph.textContent = text;
            paragraph.className = 'brand-egg-layer-text';
        }

        card.classList.toggle('is-composed', Boolean(text));

        /*
         * The ring in the drawing, so the egg fills in as the run goes rather
         * than all at once at the end.
         *
         * ⚠️ has-text / is-empty ARE THE COMPONENT'S OWN CLASSES, not a pair
         * invented here. One vocabulary for "this layer has text", so a ring
         * painted by this file and a ring rendered by the server cannot end up
         * looking different.
         */
        const ring = document.querySelector(
            `[data-brand-egg] [data-layer="${CSS.escape(layer)}"]`,
        );

        if (ring) {
            ring.classList.toggle('has-text', Boolean(text));
            ring.classList.toggle('is-empty', !text);

            // The ring says "not composed yet" by being hollow, which a screen
            // reader cannot see — the component puts it in the name instead, so
            // filling a ring has to take it back out.
            if (text && ring.hasAttribute('aria-label')) {
                ring.setAttribute(
                    'aria-label',
                    ring.getAttribute('aria-label').replace(' — sin componer', ''),
                );
            }
        }

        const button = document.querySelector(
            `[data-brand-egg-compose="${CSS.escape(layer)}"]`,
        );

        if (button && text) {
            button.textContent = 'Recomponer';
        }
    }

    /** What a finished run did, in one sentence. */
    function summarise({ composed = [], skipped = [], stopped = false }) {
        const parts = [];

        if (composed.length > 0) {
            parts.push(`${composed.length} capa${composed.length === 1 ? '' : 's'} compuesta${composed.length === 1 ? '' : 's'}.`);
        }

        // ⚠️ "Skipped" is never phrased as a failure. It means not one of the
        // entregables that layer reads has been written, which is a fact about
        // where the brand is — not something that went wrong, and not a debt
        // (ERR-07 of the beta review).
        if (skipped.length > 0) {
            parts.push(`${skipped.length} sin fuentes todavía.`);
        }

        if (stopped) {
            parts.push('La tanda se detuvo antes de terminar para no agotar el tiempo del servidor; vuelve a componer las que falten.');
        }

        return parts.join(' ') || 'No había nada que componer.';
    }

    async function compose(button) {
        // The per-card buttons carry their layer; "Componer todo" carries an
        // empty string, which the server reads as all five in dependency order.
        const layer = button.dataset.brandEggCompose;

        for (const other of composeButtons) {
            other.disabled = true;
        }

        const wasSaying = button.textContent;
        button.textContent = 'Componiendo…';
        say(layer ? 'Componiendo una capa…' : 'Componiendo las cinco capas. Puede tardar un minuto o dos…');

        const body = new FormData();

        if (layer) {
            body.append('layer', layer);
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body,
            });

            const data = await response.json().catch(() => ({}));

            // ⚠️ THE LAYERS THAT LANDED ARE PAINTED EVEN ON A FAILURE. A run
            // that dies on layer 4 has already saved 1, 2 and 3 — the server
            // sends the egg back with the error for exactly that reason, and
            // throwing the response away would show a screen that disagrees
            // with the database until somebody reloaded.
            for (const [name, text] of Object.entries(data.egg ?? {})) {
                paint(name, text);
            }

            if (!response.ok) {
                say(explain(response, data));

                return;
            }

            say(summarise(data));
        } catch {
            say(explainNetwork());
        } finally {
            button.textContent = wasSaying;

            for (const other of composeButtons) {
                // Re-read the disabled state from the card rather than
                // restoring it blindly: a layer with no sources stays disabled,
                // and a layer that has just been composed from them does not.
                other.disabled = other.hasAttribute('data-brand-egg-no-sources');
            }
        }
    }

    for (const button of composeButtons) {
        // A button that starts disabled has no sources to read. Remembered on
        // the element so the run above can restore it correctly.
        if (button.disabled) {
            button.setAttribute('data-brand-egg-no-sources', '');
        }

        button.addEventListener('click', () => compose(button));
    }
}
