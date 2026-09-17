/*
 * Dragging the line between the two rows of the admin's Brand Egg screen.
 *
 * The screen is the conversation over the drawing and the layers, and how much
 * of the page each deserves depends on what you are doing: filling a layer with
 * Brandy wants the top, reading the five paragraphs back wants the bottom.
 * Rather than guess, the line moves.
 *
 * ⚠️ IT SETS ONE CUSTOM PROPERTY AND NOTHING ELSE. The grid is
 * `var(--egg-split, 1fr) auto minmax(0, 1fr)` — the top row takes the value,
 * the handle takes its own height, and the bottom takes what is left. So the
 * default with no property set is the 1fr/1fr the stylesheet already described,
 * and this file never has to know what the layout is when nobody has dragged.
 *
 * ⚠️ STORED AS A FRACTION, APPLIED AS PIXELS. A pixel height remembered on a
 * tall monitor is most of the page on a laptop; a fraction is the same
 * proportion on both. localStorage because it is a per-viewer convenience —
 * exactly the case CLAUDE.md allows it for — and every read and write is
 * wrapped, because a private window throws rather than returning null.
 *
 * ⚠️ IT IS A CONTROL, SO IT TAKES THE KEYBOARD. Same rule the rings follow: a
 * thing that only answers a mouse is not a control. role="separator" with
 * aria-valuenow, and the arrows move it.
 */

const KEY = 'breakfast-egg-split';

/** How little either row may be left with. Below this neither is usable. */
const MIN = 140;

/** What an arrow key is worth. Small enough to aim, big enough to be felt. */
const STEP = 24;

const work = document.querySelector('[data-egg-split-work]');
const handle = document.querySelector('[data-egg-split]');

if (work && handle) {
    const store = (fraction) => {
        try {
            localStorage.setItem(KEY, String(fraction));
        } catch {
            // Private window, or site data blocked. The split still works for
            // this page; it just will not be remembered.
        }
    };

    const stored = () => {
        try {
            const value = Number.parseFloat(localStorage.getItem(KEY));

            return Number.isFinite(value) ? value : null;
        } catch {
            return null;
        }
    };

    /** The room the two rows share, once the handle has taken its own. */
    const span = () => work.clientHeight - handle.offsetHeight;

    const apply = (top, { remember = true } = {}) => {
        const room = span();

        if (room < MIN * 2) return;

        const clamped = Math.min(Math.max(top, MIN), room - MIN);

        work.style.setProperty('--egg-split', `${clamped}px`);
        handle.setAttribute('aria-valuenow', String(Math.round((clamped / room) * 100)));

        if (remember) store(clamped / room);
    };

    /** Put the remembered split back, and keep it proportional on resize. */
    const restore = () => {
        const fraction = stored();

        if (fraction === null) return;

        apply(span() * fraction, { remember: false });
    };

    restore();
    window.addEventListener('resize', restore);

    // --- dragging ---------------------------------------------------------

    let dragging = false;

    handle.addEventListener('pointerdown', (event) => {
        dragging = true;
        // Capture, so a fast drag that outruns the handle keeps sending moves
        // here instead of to whatever is under the cursor.
        handle.setPointerCapture(event.pointerId);
        work.classList.add('is-splitting');
        event.preventDefault();
    });

    handle.addEventListener('pointermove', (event) => {
        if (!dragging) return;

        apply(event.clientY - work.getBoundingClientRect().top - handle.offsetHeight / 2);
    });

    const stop = (event) => {
        if (!dragging) return;

        dragging = false;
        handle.releasePointerCapture?.(event.pointerId);
        work.classList.remove('is-splitting');
    };

    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);

    // --- the keyboard -----------------------------------------------------

    handle.addEventListener('keydown', (event) => {
        const move = {
            ArrowUp: -STEP,
            ArrowDown: STEP,
            // Home and End go to the extremes, which is where somebody wanting
            // "just the conversation" or "just the layers" is actually headed.
            Home: -Infinity,
            End: Infinity,
        }[event.key];

        if (move === undefined) return;

        event.preventDefault();

        const current = Number.parseFloat(
            getComputedStyle(work).getPropertyValue('--egg-split'),
        ) || span() / 2;

        apply(Number.isFinite(move) ? current + move : (move < 0 ? 0 : span()));
    });

    // Double-click puts it back, which is faster than dragging to the middle
    // and is what a splitter does everywhere else.
    handle.addEventListener('dblclick', () => {
        try {
            localStorage.removeItem(KEY);
        } catch {
            // See store().
        }

        work.style.removeProperty('--egg-split');
        handle.setAttribute('aria-valuenow', '50');
    });
}
