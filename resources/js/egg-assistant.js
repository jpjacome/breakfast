import { attachComposer } from './assistant-composer.js';
import { attachFiles } from './assistant-files.js';
import { drawTurnAttachments } from './turn-attachments.js';
import { renderReply } from './assistant-text.js';

/*
 * The panel that co-creates a Brand Egg with the Breakfast team.
 *
 * ⚠️ IT TURNS MARKERS INTO CARDS, AND THAT IS THE WHOLE DESIGN. Brandy wraps
 * anything she proposes saving in ⟦guardar seccion=… item=…⟧…⟦/guardar⟧;
 * everything outside a marker is ordinary prose. The asymmetry is deliberate:
 * a marker she forgets to emit degrades into a sentence somebody can read and
 * act on by hand, where a malformed JSON field would have broken the turn. The
 * convention is pinned by a test against the prompt.
 *
 * ⚠️ NOTHING HERE WRITES ON ITS OWN. A card is buttons; the write happens when
 * a person clicks one. Same rule as every other proposal in this app — there is
 * no state where the model authored a value alone (CLAUDE.md §8 rule 4).
 *
 * ⚠️ NODES, NEVER innerHTML, for the model's own output. Same reason
 * assistant-text.js says so: this is a language model's text.
 */

/*
 * ⚠️ FORGIVING ON PURPOSE. The strict form is ⟦guardar⟧…⟦/guardar⟧, and she has
 * also written ⟦guardar Una persona activa…⟦/guardar⟧ — the text running
 * straight on with no closing bracket on the opening tag. A parser that only
 * accepted the strict form dropped that to plain prose and no card appeared, so
 * a save that looked offered simply was not.
 *
 * So: `⟦guardar` plus an optional `⟧`, and everything up to `⟦/guardar⟧` is the
 * proposal. Nothing inside is read as an instruction — the entregable comes
 * from the server and the section from the ring picker.
 */
const OPEN = /⟦guardar⟧?/;
const CLOSE = '⟦/guardar⟧';

console.info('[brand-egg] egg-assistant.js loaded');

const panel = document.querySelector('[data-egg-assistant]');

if (panel) {
    const endpoint = panel.dataset.endpoint;
    const saveTemplate = panel.dataset.saveEndpoint;
    const layerTemplate = panel.dataset.layerEndpoint;

    /**
     * The entregable a card saves to, as the SERVER last reported it.
     *
     * ⚠️ NEVER READ OFF THE MARKER. She named it herself once and produced
     * `promesa` for `brand_promise` and `publico` for a section that does not
     * exist — each a 404 the person read as "no se pudo guardar". The turn tells
     * her to ask about exactly one entregable, so which one a proposal belongs
     * to is a fact the server already holds. It comes back with every reply.
     */
    let target = null;
    const thread = panel.querySelector('[data-egg-thread]');
    const checklist = panel.querySelector('[data-egg-checklist]');
    const form = panel.querySelector('[data-egg-form]');
    const field = panel.querySelector('#egg-message');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const composer = attachComposer(field, form);

    let busy = false;

    /*
     * ⚠️ THE SAME MODULE THE OTHER PANELS USE. Paste, drop and the picker were
     * inline in assistant.js, so this panel shipped with no way to attach
     * anything — see assistant-files.js.
     */
    const files = attachFiles(panel, field, {
        busy: () => busy,
        say: (message) => addTurn('assistant', message),
    });

    /** Which ring is selected. It decides where an answer is allowed to land. */
    const currentLayer = () =>
        panel.querySelector('[data-egg-layer]:checked')?.value ?? null;

    /**
     * Parse a reply into prose and proposals, in order.
     *
     * Returns a flat list so the turn renders in the sequence she wrote it —
     * a card between two paragraphs stays between them.
     */
    const parse = (text) => {
        const parts = [];
        let rest = text;

        for (;;) {
            const open = rest.match(OPEN);

            if (!open) break;

            const before = rest.slice(0, open.index);
            const after = rest.slice(open.index + open[0].length);
            const end = after.indexOf(CLOSE);

            // An opening marker with no close is not a card. Leaving the rest
            // as prose keeps a truncated answer readable instead of eating it.
            if (end === -1) break;

            if (before.trim()) parts.push({ kind: 'text', text: before });

            /*
             * ⚠️ TOLERANT OF QUOTES, AND IT HAS TO BE. The prompt asks for
             * `seccion=esencia`, and a model writes `seccion="esencia"` often
             * enough that a parser which only accepts the bare form leaves the
             * attribute undefined — the URL then keeps its __LAYER__
             * placeholder, 404s, and the card says "no se pudo guardar" with no
             * hint as to why. Single quotes, double quotes or none.
             */
            /*
             * ⚠️ THE MARKER CARRIES NOTHING BUT THE TEXT. Any attributes she
             * writes anyway are read and then ignored: the entregable comes
             * from the server's `target`, and the section from the ring picker.
             * She cannot name either, so she cannot get either wrong.
             */
            parts.push({
                kind: 'card',
                attrs: { item: target },
                text: after.slice(0, end).trim(),
            });

            rest = after.slice(end + CLOSE.length);
        }

        if (rest.trim()) parts.push({ kind: 'text', text: rest });

        return parts;
    };

    /**
     * The entregable's own name, for the line that tells Brandy to carry on.
     *
     * Read off the checklist she was just sent rather than kept in a second
     * list here — the enum's labels live on the server and a copy of them in
     * this file is a vocabulary that drifts.
     */
    const label = (item) => {
        const text = checklist.textContent ?? '';
        const found = text.split('\n').find((line) => line.toLowerCase().includes(item));

        return found?.replace(/[✅⬜➖]/g, '').split('·')[0].trim() || item;
    };

    /** One saved-or-not proposal, as buttons a person clicks. */
    const card = ({ attrs, text }) => {
        const wrap = document.createElement('div');
        wrap.className = 'egg-card';

        const body = document.createElement('textarea');
        body.className = 'egg-card-text';
        body.rows = Math.min(8, Math.ceil(text.length / 60) + 1);
        body.value = text;
        wrap.append(body);

        const actions = document.createElement('div');
        actions.className = 'egg-card-actions';

        const done = (said) => {
            actions.replaceChildren();
            const note = document.createElement('p');
            note.className = 'egg-card-done';
            note.textContent = said;
            wrap.append(note);
            body.readOnly = true;
        };

        const save = async (toLayer, toItem) => {
            actions.querySelectorAll('button').forEach((b) => (b.disabled = true));

            /*
             * ⚠️ THE SELECTED RING, ALWAYS. Not "when she omits it" — she is not
             * asked for the section at all any more. She wrote `seccion=publico`
             * once, which is not a section, and the save 404'd. The ring picker
             * is the one thing on this screen that cannot be wrong about which
             * layer somebody is filling.
             */
            const section = currentLayer();

            console.info('[brand-egg] save clicked', { section, toItem, toLayer, attrs });

            try {
                if (toItem) {
                    const url = saveTemplate
                        .replace('__LAYER__', section)
                        .replace('__ITEM__', toItem);

                    const response = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ texto: body.value }),
                    });

                    console.info('[brand-egg] PATCH', url, response.status);

                    if (!response.ok) throw new Error(`save failed ${response.status}`);

                    const data = await response.json();
                    if (data.checklist) renderReply(checklist, data.checklist);
                }

                if (toLayer) {
                    /*
                     * ⚠️ IT REALLY SAVES. This used to only fill the layer's
                     * textarea and leave somebody to press Guardar — so "En los
                     * dos" wrote the entregable and quietly did nothing to the
                     * Egg. A button that says it saves has to save.
                     *
                     * The route answers with a redirect, like every other admin
                     * write; the status is all this needs.
                     */
                    const layerResponse = await fetch(
                        layerTemplate.replace('__LAYER__', section),
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': token,
                                Accept: 'application/json',
                            },
                            // Laravel reads the method from the payload for a
                            // route declared PUT — the same spoof the form does.
                            body: JSON.stringify({ _method: 'PUT', text: body.value }),
                        },
                    );

                    if (!layerResponse.ok) throw new Error('layer save failed');

                    // And the screen catches up, so the layer beside the panel
                    // is not still showing yesterday's text.
                    const field = document.querySelector(
                        `[data-brand-egg-layer-input="${attrs.seccion}"]`,
                    );

                    if (field) {
                        field.value = body.value;

                        const shown = field.closest('section')?.querySelector('[data-brand-egg-text]');

                        if (shown) shown.textContent = body.value;
                    }
                }

                const said = toLayer && toItem
                    ? 'Guardado en el entregable, y puesto en el Brand Egg para que lo revises.'
                    : toItem
                      ? 'Guardado en el entregable.'
                      : 'Puesto en el Brand Egg para que lo revises.';

                done(said);

                /*
                 * ⚠️ AND SHE CARRIES ON. The save is not the end of the turn —
                 * the next question is. Echo is off because the person did not
                 * type this: the card said what it was doing, and a line
                 * appearing in the transcript as though they had typed
                 * "guardado" would be putting words in their mouth.
                 */
                sendTurn(
                    toItem
                        ? `Guardado en «${label(toItem)}». Sigue.`
                        : 'Guardado en el Brand Egg. Sigue.',
                    { echo: false },
                );
            } catch (error) {
                // ⚠️ The reason goes to the console. "No se pudo guardar" on its
                // own sent somebody hunting through a Laravel log that had
                // nothing in it, because a 404 dies before the app's handler.
                console.error('[brand-egg] save failed', { section, toItem, attrs, error });

                actions.querySelectorAll('button').forEach((b) => (b.disabled = false));
                done('No se pudo guardar. Vuelve a intentarlo.');
            }
        };

        const button = (label, primary, onClick) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = `admin-button admin-button-sm ${
                primary ? 'admin-button-primary' : 'admin-button-outline'
            }`;
            b.textContent = label;
            b.addEventListener('click', onClick);
            actions.append(b);
        };

        // Whatever the server said was on the table when it sent this reply.
        const item = attrs.item ?? null;

        /*
         * ⚠️ "En los dos" IS FIRST BECAUSE IT IS THE COMMON CASE — what they
         * just said genuinely is the entregable AND material for the section.
         * It is still a choice: nothing saves without a click.
         */
        if (item) {
            button('En los dos', true, () => save(true, item));
            button('Sólo en el Brand Egg', false, () => save(true, null));
            button('Sólo en el entregable', false, () => save(false, item));
        } else {
            button('Guardar en el Brand Egg', true, () => save(true, null));
        }

        button('Descartar', false, () => done('Descartado.'));

        wrap.append(actions);

        return wrap;
    };

    const addTurn = (who, text, carried = []) => {
        const row = document.createElement('div');
        row.className = `egg-turn egg-turn-${who}`;

        if (who === 'assistant') {
            parse(text).forEach((part) => {
                if (part.kind === 'card') {
                    console.info('[brand-egg] card parsed', part.attrs);
                    row.append(card(part));

                    return;
                }

                const prose = document.createElement('div');
                renderReply(prose, part.text);
                row.append(prose);
            });
        } else {
            row.textContent = text;

            // What this turn carried, drawn the same way as on every other
            // panel — see turn-attachments.js.
            if (carried.length) {
                drawTurnAttachments(row, carried.map((file) => file.name));
            }
        }

        thread.append(row);
        row.scrollIntoView({ block: 'nearest' });
    };

    /*
     * What Up recalls in the composer, read before the renderer below replaces
     * these spans with nodes — until it does, one holds exactly the characters
     * that were typed.
     *
     * ⚠️ ONLY THIS PERSON'S OWN TURNS. The Egg's thread is keyed on the brand,
     * not on a user: building one is team work, so somebody else's question is
     * printed here and is not something you sent. The server marks yours.
     */
    composer.seed(
        [...thread.querySelectorAll('.egg-turn-user[data-own] [data-egg-message]')]
            .map((node) => node.textContent),
    );

    // The server-rendered history goes through the same renderer, so a turn
    // read today looks like the same turn read tomorrow (CLAUDE.md §7).
    thread.querySelectorAll('[data-egg-message]').forEach((node) => {
        const text = node.textContent;
        const rendered = document.createElement('div');

        parse(text).forEach((part) => {
            if (part.kind === 'card') {
                // ⚠️ History renders a card's TEXT, not its buttons. Whether it
                // was accepted is already answered by the checklist above; a
                // live button on yesterday's proposal would offer to save
                // something that may have been superseded twice since.
                const old = document.createElement('blockquote');
                old.className = 'egg-card-past';
                old.textContent = part.text;
                rendered.append(old);

                return;
            }

            const prose = document.createElement('div');
            renderReply(prose, part.text);
            rendered.append(prose);
        });

        node.replaceWith(rendered);
    });

    /**
     * One turn, and the only thing that talks to the endpoint.
     *
     * ⚠️ ACCEPTING A CARD CALLS THIS TOO. Without it the conversation dead-ends
     * on the card: the entregable is saved, the tick moves, and Brandy sits
     * there with nothing to say until somebody types — which reads as her
     * having lost the thread at the exact moment she was most useful.
     */
    const sendTurn = async (message, { echo = true } = {}) => {
        if (echo) addTurn('user', message, files.files());

        const waiting = document.createElement('div');
        waiting.className = 'egg-turn egg-turn-assistant egg-turn-waiting';
        waiting.textContent = 'Pensando…';
        thread.append(waiting);
        waiting.scrollIntoView({ block: 'nearest' });

        const carrying = files.files();

        busy = true;

        try {
            /*
             * ⚠️ MULTIPART ONLY WHEN THERE IS SOMETHING TO CARRY. A typed turn
             * is the common case by a long way, and FormData on every one of
             * them would send the whole conversation as form fields for no
             * reason. JSON otherwise, exactly as before.
             */
            const body = carrying.length === 0
                ? JSON.stringify({ message, layer: currentLayer() })
                : (() => {
                    const form = new FormData();
                    form.append('message', message);

                    if (currentLayer()) form.append('layer', currentLayer());

                    carrying.forEach((file) => form.append('files[]', file));

                    return form;
                })();

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    // ⚠️ NO Content-Type FOR FormData. Setting it by hand drops
                    // the boundary the browser would have added, and every file
                    // arrives empty.
                    ...(carrying.length === 0 ? { 'Content-Type': 'application/json' } : {}),
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                },
                body,
            });

            const data = await response.json();
            waiting.remove();

            if (!response.ok) {
                addTurn('assistant', data.error ?? 'No obtuve respuesta.');

                return;
            }

            if (data.checklist) renderReply(checklist, data.checklist);

            // ⚠️ SET BEFORE THE TURN IS DRAWN. The cards in this reply are for
            // the entregable the server says is on the table, and they are
            // built while addTurn() runs.
            target = data.target ?? null;

            addTurn('assistant', data.reply);
        } catch {
            waiting.remove();
            addTurn('assistant', 'No se pudo contactar al asistente.');
        } finally {
            busy = false;
            files.clear();
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const message = field.value.trim();

        if (!message) return;

        composer.reset();
        sendTurn(message);
    });

    // Switching rings asks for that section's checklist without spending a turn.
    panel.querySelectorAll('[data-egg-layer]').forEach((radio) => {
        radio.addEventListener('change', () => {
            checklist.replaceChildren();
        });
    });
}
