/**
 * The entregables board: filtering, and the counter that keeps up with typing.
 *
 * Filtering HIDES rows, it never removes them from the form. Every textarea
 * stays submitted whatever is on screen — a field that stopped being posted
 * would read as an entregable somebody emptied, and the save would clear it.
 *
 * The counter belongs to the page from the first keystroke. The server paints
 * it once on load; after that it tracks what is actually in the boxes, because
 * the useful moment is the stretch between typing and saving.
 */

// The copy-link buttons in the brand's folder, shared with the file manager.
import './copy-link.js';

const list = document.querySelector('[data-items]');

if (list) {
    const items = [...list.querySelectorAll('.process-item')];
    const buttons = [...document.querySelectorAll('.process-filters button')];
    const filledOut = document.querySelector('[data-count-filled]');
    const requiredOut = document.querySelector('[data-count-required]');

    const isFilled = (item) => item.querySelector('[data-entregable]').value.trim() !== '';
    const isRequired = (item) => item.dataset.required === '1';

    const matches = {
        todos: () => true,
        obligatorios: isRequired,
        pendientes: (item) => !isFilled(item),
        llenos: isFilled,
    };

    // Whichever filter the blade marked pressed wins: the process screen opens
    // on Todos, /clientes/nueva on Pendientes, and the JS should not have a
    // second opinion about which.
    let active = buttons.find((b) => b.getAttribute('aria-pressed') === 'true')?.dataset.filter
        ?? 'todos';

    /**
     * Recount, and repaint the filled/empty edge.
     *
     * ⚠️ DOES NOT RE-FILTER. Visibility changes only when somebody presses a
     * filter button — see refilter(). Re-running the filter on every keystroke
     * meant that under "Pendientes" an entregable disappeared the instant it
     * stopped being pending: type into it, or approve a proposal for it, and
     * the row you were looking at vanished. Twenty-three approvals once looked
     * like they had done nothing at all.
     */
    function paint() {
        let filled = 0;
        let required = 0;

        for (const item of items) {
            const full = isFilled(item);

            if (full) {
                filled++;
                if (isRequired(item)) required++;
            }

            // Drives the dashed/solid edge without a repaint of the markup.
            item.dataset.filled = full ? '1' : '0';
        }

        if (filledOut) filledOut.textContent = filled;
        if (requiredOut) requiredOut.textContent = required;
    }

    function refilter() {
        for (const item of items) {
            item.hidden = !matches[active](item);
        }
    }

    for (const button of buttons) {
        button.addEventListener('click', () => {
            active = button.dataset.filter;

            for (const other of buttons) {
                other.setAttribute('aria-pressed', String(other === button));
            }

            paint();
            refilter();
        });
    }

    // 'input' rather than 'change': the count should move as somebody types,
    // not when they leave the box.
    list.addEventListener('input', (event) => {
        if (event.target.matches('[data-entregable]')) paint();
    });

    paint();
    refilter();
}

/* ---------------------------------------------------------------------------
   Insert a file's link into an entregable
   ---------------------------------------------------------------------------
   Section 5 of the beta review: twelve of the 48 need images and video, and the
   entregable stays text — so what goes in is the file's link, and the brand
   page renders a link to one of our images as the image.

   Typing the URL by hand is the only part of that a person could get wrong, so
   this is the whole feature: pick the file, the link lands at the cursor.
   --------------------------------------------------------------------------- */

for (const button of document.querySelectorAll('[data-insert-file]')) {
    button.addEventListener('click', () => {
        const field = document.getElementById(`entregable_${button.dataset.target}`);

        if (!field) return;

        const url = button.dataset.insertFile;
        const at = field.selectionStart ?? field.value.length;
        const before = field.value.slice(0, at);
        const after = field.value.slice(field.selectionEnd ?? at);

        // A link needs whitespace around it or it runs into the word beside it
        // and stops being a link at all — the pattern that finds URLs on the
        // brand page reads up to the next space.
        const lead = before === '' || /\s$/.test(before) ? '' : '\n';
        const tail = after === '' || /^\s/.test(after) ? '' : '\n';

        field.value = `${before}${lead}${url}${tail}${after}`;

        // The counter above the board watches for input, and it is not told by
        // setting .value from script.
        field.dispatchEvent(new Event('input', { bubbles: true }));

        // Put the caret after what was just inserted, and show it: the
        // entregable can be a long way from the file list that was clicked.
        const caret = before.length + lead.length + url.length;
        field.focus();
        field.setSelectionRange(caret, caret);

        // Close the picker — it did its job, and leaving twenty files open
        // between every entregable makes the board unreadable.
        button.closest('details')?.removeAttribute('open');
    });
}

/* ---------------------------------------------------------------------------
   Don't lose a reading on the way out
   ---------------------------------------------------------------------------
   ERR-03 of the beta review: "la primera interpretación no se guardó". Two
   things caused that. The batches now persist their cards server-side (see
   BrandOnboardingController::keepProposals), which is the half that mattered
   most — but a board with typed-but-unsaved text is still one browser-close
   away from being retyped, and a brandbook's worth of entregables is a long
   afternoon to lose.

   So: the browser's own "leave site?" dialog, armed only while there is
   actually something to lose. Deliberately not an autosave — the board is
   saved by a person clicking Guardar, and a screen that wrote entregables on
   its own would be exactly the provenance problem CLAUDE.md §8 rule 4 exists
   to avoid.
   --------------------------------------------------------------------------- */

const board = document.querySelector('[data-items]')?.closest('form');

if (board) {
    // What the fields held when the page loaded. Compared rather than a dirty
    // flag, so typing a word and deleting it again does not arm the warning.
    const fields = [...board.querySelectorAll('[data-entregable]')];
    const original = new Map(fields.map((field) => [field, field.value]));

    // A proposal card waiting to be accepted is unsaved work too: it cost a
    // real call to produce and it is gone with the tab.
    const pendingCards = () => document.querySelectorAll('[data-proposals] button').length > 0;

    const dirty = () => fields.some((field) => field.value !== original.get(field));

    window.addEventListener('beforeunload', (event) => {
        if (!dirty() && !pendingCards()) return;

        // Every browser shows its own wording now; returning a value is what
        // asks for the dialog at all.
        event.preventDefault();
        event.returnValue = '';
    });

    // Saving is leaving on purpose.
    board.addEventListener('submit', () => {
        fields.forEach((field) => original.set(field, field.value));
    });
}
