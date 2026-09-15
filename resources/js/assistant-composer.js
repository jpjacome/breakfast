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
 *
 * UP RECALLS WHAT YOU SENT — and it lives here rather than in the panels for
 * the same reason Enter does: three surfaces, one control, and a key that did
 * different things on different screens would be worse than one that did
 * nothing at all.
 *
 * It is also the ANSWER TO "edit a question you already sent" (item 7 of the
 * cycle), and deliberately not that feature. Editing a sent turn in place means
 * rewriting the history the model already answered from: two versions of one
 * question, an answer attached to the version nobody can see any more, and a
 * thread that no longer records what was actually asked. Recalling the text
 * into an empty box is the same gesture with none of that — the sent turn stays
 * exactly as it was sent, and what you edit is a NEW question.
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

/** How many sent questions to keep. Long enough to find one, short enough to be memory. */
const MAX_HISTORY = 50;

/**
 * Whether the caret sits on the first (or last) line of the box.
 *
 * THIS IS WHAT KEEPS THE ARROW KEYS USABLE. The field is a textarea, so Up and
 * Down are how somebody moves between the lines of a long question. They may
 * only be taken over at the EDGES — on the first line there is nowhere up to
 * go, so recall is free; anywhere else it would trap the caret and make a
 * multi-paragraph question impossible to edit.
 */
function onFirstLine(field) {
    return !field.value.slice(0, field.selectionStart).includes('\n');
}

function onLastLine(field) {
    return !field.value.slice(field.selectionEnd).includes('\n');
}

/** Put the caret at the end, which is where you carry on typing from. */
function caretToEnd(field) {
    field.selectionStart = field.selectionEnd = field.value.length;
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

    /*
     * Sent questions, oldest first, and the edits made while walking them.
     *
     * `entries` is what was actually sent and never changes. `working` is the
     * same list with whatever has been typed on top of it, so stepping away
     * from a half-edited recall and back again does not throw the edit away —
     * the behaviour a shell has, and the one people expect without being able
     * to say so. `index === null` means "in the live draft" rather than a
     * position in the list.
     *
     * In memory only, deliberately. The thread above is re-rendered by the
     * server on every load, so the questions are still on screen after a
     * reload; persisting a second copy in the browser would be a private cache
     * of what somebody asked, kept for nothing the page needs.
     */
    const entries = [];
    const working = [];
    let index = null;
    let draft = '';

    /** Keep whatever is being edited right now, recall or draft. */
    const remember = (value) => {
        if (index === null) draft = value;
        else working[index] = value;
    };

    const show = (value) => {
        field.value = value;
        fit(field);
        caretToEnd(field);
    };

    field.addEventListener('input', () => {
        fit(field);
        remember(field.value);
    });

    field.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            // A held modifier means the person is selecting or jumping words,
            // not asking for the last thing they sent.
            if (event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) return;

            if (event.key === 'ArrowUp') {
                if (entries.length === 0 || !onFirstLine(field)) return;

                // Already at the oldest: stay put rather than wrapping round,
                // which loses your place with nothing on screen to say it did.
                if (index === 0) return;

                event.preventDefault();
                index = index === null ? entries.length - 1 : index - 1;
                show(working[index]);

                return;
            }

            // Down only means anything while walking the list. Otherwise it is
            // an ordinary caret move and must stay one.
            if (index === null || !onLastLine(field)) return;

            event.preventDefault();

            if (index === entries.length - 1) {
                index = null;
                show(draft);
            } else {
                index += 1;
                show(working[index]);
            }

            return;
        }

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
        /**
         * Clear the box after a send, and remember what was in it.
         *
         * The panels call this with the question still in the field, so there
         * is nothing to pass: what was sent is what is here. A send that fails
         * puts the text back, which leaves it both in the box and in the list —
         * right either way, because it was sent.
         */
        reset() {
            const sent = field.value.trim();

            // Ignore a repeat of the last one: needing two Ups to get past a
            // question you happened to ask twice is a list working against you.
            if (sent !== '' && sent !== entries[entries.length - 1]) {
                entries.push(sent);

                if (entries.length > MAX_HISTORY) entries.shift();
            }

            /*
             * Throw away every pending edit, not just this one.
             *
             * The scratch copies belong to the walk that has just ended. Left
             * alone they corrupt the list: typing a new question while standing
             * on an old one writes the new text into THAT entry's working copy,
             * so the next Up serves back something nobody ever sent. Sending is
             * what settles it, exactly as accepting a line does in a shell.
             */
            working.length = 0;
            working.push(...entries);

            index = null;
            draft = '';
            field.value = '';
            fit(field);
        },
    };
}
