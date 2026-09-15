/**
 * Autosave for /clientes/nueva.
 *
 * The brand does not exist when this screen opens. The first save that carries
 * anything worth keeping brings a Borrador row into being — see
 * App\Actions\StartBrandDraft, the only place that happens — and from then on
 * every save and every assistant turn names that row.
 *
 * FIVE MINUTES, AND ONLY IF SOMETHING CHANGED. A timer that posts regardless
 * would create a draft for anybody who opened the screen and walked away, and
 * abandoned drafts are a real cost: they sit in the brand list.
 *
 * It also saves on the way out — pagehide rather than beforeunload, because
 * beforeunload does not fire reliably on mobile. Losing ten minutes of typing
 * to a closed tab is the failure this whole mechanism exists to prevent.
 *
 * AND THERE IS A BUTTON. Everything above is a promise made by a timer nobody
 * can see, on a screen that can hold an hour of work before Crear marca is
 * anywhere near pressable. Guardar borrador is the same save, run on demand
 * and — unlike the timer — answering out loud, because somebody who presses it
 * is asking a question.
 */

const form = document.querySelector('[data-draft-form]');

if (form) {
    const slugField = form.querySelector('[data-draft-slug]');
    const nameLabel = document.querySelector('[data-draft-name]');
    const statusLine = document.querySelector('[data-draft-status]');
    const saveButton = document.querySelector('[data-draft-save]');
    const fields = [...form.querySelectorAll('[data-draft-field]')];
    const board = [...form.querySelectorAll('[data-entregable]')];
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const INTERVAL_MS = 5 * 60 * 1000;

    let dirty = false;
    let saving = false;

    /**
     * Everything worth keeping: the brand's own fields AND the 48 entregables.
     *
     * The board was left out of the first version of this, and approving
     * twenty-three proposals then closing the tab lost every one of them —
     * while the screen said the page saved itself. An approval is a person
     * accepting something; it has to survive.
     */
    const snapshot = () => ({
        ...Object.fromEntries(fields.map((field) => [field.name, field.value])),
        entregables: Object.fromEntries(board.map((el) => [el.dataset.entregable, el.value])),
    });

    let saved = JSON.stringify(snapshot());

    const changed = () => JSON.stringify(snapshot()) !== saved;

    /**
     * The one line that says where the draft stands.
     *
     * It is aria-live, so whatever goes here is also read out: one place for
     * "guardado a las 14:02" and for "no se pudo", because two of them would
     * mean the good news and the bad news could be on screen at once.
     */
    const say = (text) => {
        if (statusLine) statusLine.textContent = text;
    };

    for (const field of fields) {
        field.addEventListener('input', () => { dirty = true; });
    }

    /**
     * The board saves sooner than the timer.
     *
     * Five minutes is fine for somebody typing a contact name. It is far too
     * long for a batch of approvals, which arrive in one burst and are the
     * thing most worth not losing — so a change to an entregable schedules a
     * save a few seconds out instead of waiting for the tick.
     */
    let soon = null;

    for (const el of board) {
        el.addEventListener('input', () => {
            dirty = true;
            clearTimeout(soon);
            soon = setTimeout(() => save(), 4000);
        });
    }

    /**
     * The assistant shares this page and creates the same draft. When it does,
     * it reports the slug back so the two never fork the brand into two rows.
     */
    document.addEventListener('draft:started', (event) => {
        if (event.detail?.slug && slugField && !slugField.value) {
            slugField.value = event.detail.slug;
            rememberInUrl(event.detail.slug);
        }

        if (event.detail?.name && nameLabel) {
            nameLabel.textContent = event.detail.name;
        }
    });

    /** So a reload comes back to the same draft instead of starting another. */
    function rememberInUrl(slug) {
        const url = new URL(window.location.href);

        if (url.searchParams.get('borrador') === slug) return;

        url.searchParams.set('borrador', slug);
        window.history.replaceState({}, '', url);
    }

    /**
     * @param keepalive  let the browser finish this after the page is gone.
     * @param manual     somebody pressed Guardar borrador, so it answers.
     */
    async function save({ keepalive = false, manual = false } = {}) {
        // A manual save posts even with nothing changed. The person pressed the
        // button to be told where they stand, and a button that silently does
        // nothing is the same button as one that silently fails.
        if (saving || (! manual && ! changed())) return;

        saving = true;

        if (saveButton) saveButton.disabled = true;

        const payload = { draft: slugField?.value || null, ...snapshot() };

        // What is going up, captured now — not what is on screen when the reply
        // lands. Marking the later state as saved is how a line typed during a
        // save is never posted again: it would already count as stored.
        const posted = JSON.stringify(snapshot());

        clearTimeout(soon);

        try {
            const response = await fetch(form.dataset.saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
                // Lets the browser finish the request after the page is gone.
                keepalive,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.saved) {
                if (manual) {
                    say(response.ok
                        ? 'Todavía no hay nada que guardar.'
                        : 'No se pudo guardar el borrador. Inténtalo otra vez.');
                }

                return;
            }

            saved = posted;

            // What is on screen may already have moved past what was posted —
            // that difference is still unsaved, and the next tick has to know.
            dirty = changed();

            if (slugField && data.draft) {
                slugField.value = data.draft;
                rememberInUrl(data.draft);
            }

            if (nameLabel && data.name) nameLabel.textContent = data.name;
            if (data.at) say(`Borrador guardado a las ${data.at}.`);
        } catch (error) {
            // The timer stays silent. It runs behind somebody's typing, and an
            // error banner for a failed background save would interrupt the
            // work it exists to protect: the next tick retries, and Crear marca
            // saves everything regardless.
            //
            // A press of the button is not that. It was a question, and the
            // honest answer to "did that save?" is sometimes no.
            if (manual) say('No se pudo guardar el borrador. Inténtalo otra vez.');
        } finally {
            saving = false;

            if (saveButton) saveButton.disabled = false;
        }
    }

    saveButton?.addEventListener('click', () => save({ manual: true }));

    setInterval(() => { if (dirty) save(); }, INTERVAL_MS);

    window.addEventListener('pagehide', () => { if (dirty) save({ keepalive: true }); });

    // Submitting saves everything anyway, so the draft post would be a wasted
    // round trip racing the real one.
    form.addEventListener('submit', () => { dirty = false; });
}
