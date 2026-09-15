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

        // A card is not a control — it lights, and that is all. A button in
        // the row is, and it selects exactly as a ring does. Asking the
        // element what it is keeps both behaviours in this one loop.
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
