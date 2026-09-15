/**
 * The box you type a question into, on every assistant panel.
 *
 * ONE MODULE FOR ALL THREE — Brandy on the client dashboard, the assistant on
 * /admin, and the brandbook reader beside the entregables board. They are one
 * control as far as anybody using them is concerned, and a Shift+Enter that
 * worked on one screen and sent the message on another would say otherwise.
 *
 * WHY IT EXISTS. The beta review: "los mensajes de consulta largos se extienden
 * horizontalmente y no permiten leer con facilidad todo lo escrito antes de
 * enviarlo. Presionar Enter también envía el mensaje inmediatamente." Both
 * halves were the same cause — the field was an <input type="text">, which is a
 * single line by construction and submits its form on Enter. A textarea fixes
 * the reading; the sending needs the rules below.
 *
 * ⚠️ ENTER MEANS DIFFERENT THINGS ON DIFFERENT DEVICES, and getting this
 * backwards makes the box unusable rather than merely awkward:
 *
 *   desktop  Enter sends, Shift+Enter breaks the line. The convention every
 *            chat app has trained people into, and the report asks for it.
 *   touch    Enter NEVER sends. The on-screen Return key is how somebody writes
 *            a second paragraph, and there is no Shift to hold — so the send
 *            button is the only way out, exactly as the report says.
 *
 * The split is by pointer, not by screen width: a narrow desktop window still
 * has a keyboard, and a tablet with a wide screen still does not have a Shift
 * key under its thumb.
 */

/** Roughly eight lines, after which it scrolls instead of growing. */
const MAX_ROWS = 8;

/**
 * True where Enter must not send.
 *
 * `any-pointer: coarse` rather than `pointer: coarse`: a laptop with a
 * touchscreen has both, and there the keyboard is the one being typed on.
 * Matching on the primary pointer is what gets that case right.
 */
function isTouch() {
    return window.matchMedia?.('(pointer: coarse)').matches ?? false;
}

/**
 * Grow the field to fit what is in it, up to the cap.
 *
 * Height is reset to auto first because scrollHeight never shrinks on its own:
 * without it the box grows as you type and then stays tall after you delete,
 * which looks broken.
 */
function fit(field) {
    const style = getComputedStyle(field);
    const line = parseFloat(style.lineHeight) || 20;
    const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
    const max = (line * MAX_ROWS) + padding;

    field.style.height = 'auto';

    const wanted = field.scrollHeight;

    field.style.height = `${Math.min(wanted, max)}px`;
    field.style.overflowY = wanted > max ? 'auto' : 'hidden';
}

/**
 * Wire one composer.
 *
 * @param {HTMLTextAreaElement} field  the question box
 * @param {HTMLFormElement} form      the form it submits
 * @returns {{reset: () => void}}     reset() after a successful send
 */
export function attachComposer(field, form) {
    if (!field || !form) return { reset: () => {} };

    fit(field);
    field.addEventListener('input', () => fit(field));

    field.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) return;

        // An IME composing kanji or accents uses Enter to accept a candidate.
        // Sending there would eat the word somebody was in the middle of.
        if (event.isComposing || event.keyCode === 229) return;

        if (isTouch()) return;

        event.preventDefault();
        // requestSubmit, not submit(): it fires the submit event the panels
        // listen on, and form.submit() would bypass them and reload the page.
        form.requestSubmit();
    });

    return {
        reset() {
            field.value = '';
            fit(field);
        },
    };
}
