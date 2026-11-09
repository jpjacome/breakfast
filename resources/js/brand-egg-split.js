/*
 * Dragging the seam between the two panes of the admin's Brand Egg screen.
 *
 * How much of the page each pane deserves depends on what you are doing:
 * filling a layer with Brandy wants the top, reading the five paragraphs back
 * wants the bottom. Rather than guess, the seam moves.
 *
 * ⚠️ PERCENTAGES, NOT PIXELS, AND THAT IS THE WHOLE DESIGN. One number moves
 * and the other is 100 minus it. A percentage survives a window resize by
 * itself — a pixel height remembered on a tall monitor is most of the page on
 * a laptop, and keeping it proportional would mean storing a fraction and
 * recomputing on every resize. The browser already does that for us.
 *
 * ⚠️ THE STYLESHEET'S DEFAULT IS NEVER TOUCHED. It says `50% 50%`; this file
 * only ever writes an inline `grid-template-rows`, and removing that inline
 * style hands the layout straight back. So a bug in here cannot change what
 * the screen looks like before anybody drags — which is exactly how the first
 * attempt at this went wrong: it altered the tracks themselves and the top
 * pane computed to height 0.
 *
 * ⚠️ THE HANDLE IS NOT A GRID ITEM. It is absolutely positioned over the seam,
 * so it takes part in no track sizing at all. A third track was what broke the
 * layout last time.
 *
 * ⚠️ HALF THE GAP COMES OFF EACH TRACK. Percentages resolve against the
 * container's full height and ignore `gap`, so a plain `50% 50%` overflows by
 * exactly the gap and the bottom pane hangs past the fold.
 */

const KEY = 'breakfast-egg-split';

/** How close to an edge the seam may get, in percent. Below this a pane is a strip. */
const MIN = 12;

/** What an arrow key is worth. Small enough to aim, big enough to be felt. */
const STEP = 4;

/** Half of the `gap: 1rem` between the panes, in rem. */
const HALF_GAP = 0.5;

const work = document.querySelector('[data-egg-split-work]');
const handle = document.querySelector('[data-egg-split]');

if (work && handle) {
    const store = (top) => {
        try {
            localStorage.setItem(KEY, String(top));
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

    /** Where the seam sits now, as a percentage of the pane area. */
    let top = stored() ?? 50;

    const apply = (next, { remember = true } = {}) => {
        top = Math.min(Math.max(next, MIN), 100 - MIN);

        work.style.gridTemplateRows =
            `calc(${top}% - ${HALF_GAP}rem) calc(${100 - top}% - ${HALF_GAP}rem)`;

        handle.style.top = `${top}%`;
        handle.setAttribute('aria-valuenow', String(Math.round(top)));

        if (remember) store(top);
    };

    // Only if something was remembered. With nothing stored the stylesheet's
    // own 50% 50% stands and no inline style is written at all.
    if (stored() !== null) apply(top, { remember: false });
    else handle.style.top = '50%';

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

        const box = work.getBoundingClientRect();

        if (box.height === 0) return;

        apply(((event.clientY - box.top) / box.height) * 100);
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

    // It is a control, so it takes the keyboard — the same rule the rings
    // follow. A thing that only answers a mouse is not a control.
    handle.addEventListener('keydown', (event) => {
        const move = {
            ArrowUp: -STEP,
            ArrowDown: STEP,
            Home: -100,
            End: 100,
        }[event.key];

        if (move === undefined) return;

        event.preventDefault();
        apply(top + move);
    });

    // Double-click puts it back, which is faster than dragging to the middle
    // and is what a splitter does everywhere else.
    handle.addEventListener('dblclick', () => {
        try {
            localStorage.removeItem(KEY);
        } catch {
            // See store().
        }

        top = 50;
        work.style.removeProperty('grid-template-rows');
        handle.style.top = '50%';
        handle.setAttribute('aria-valuenow', '50');
    });
}
