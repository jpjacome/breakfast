import { attachComposer } from './assistant-composer.js';
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

const OPEN = /⟦guardar([^⟧]*)⟧/;
const CLOSE = '⟦/guardar⟧';

const panel = document.querySelector('[data-egg-assistant]');

if (panel) {
    const endpoint = panel.dataset.endpoint;
    const saveTemplate = panel.dataset.saveEndpoint;
    const thread = panel.querySelector('[data-egg-thread]');
    const checklist = panel.querySelector('[data-egg-checklist]');
    const form = panel.querySelector('[data-egg-form]');
    const field = panel.querySelector('#egg-message');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const composer = attachComposer(field, form);

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

            parts.push({
                kind: 'card',
                attrs: Object.fromEntries(
                    [...open[1].matchAll(/(\w+)=([\w-]+)/g)].map((m) => [m[1], m[2]]),
                ),
                text: after.slice(0, end).trim(),
            });

            rest = after.slice(end + CLOSE.length);
        }

        if (rest.trim()) parts.push({ kind: 'text', text: rest });

        return parts;
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

            try {
                if (toItem) {
                    const url = saveTemplate
                        .replace('__LAYER__', attrs.seccion)
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

                    if (!response.ok) throw new Error('save failed');

                    const data = await response.json();
                    if (data.checklist) renderReply(checklist, data.checklist);
                }

                if (toLayer) {
                    // The layer's own textarea is on this same screen — the
                    // card fills it rather than posting, so the person still
                    // presses Guardar on the layer they are about to change.
                    const target = document.querySelector(
                        `[data-brand-egg-layer-input="${attrs.seccion}"]`,
                    );

                    if (target) {
                        target.value = body.value;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                        target.closest('section')?.scrollIntoView({ block: 'center' });
                    }
                }

                done(
                    toLayer && toItem
                        ? 'Guardado en el entregable, y puesto en el Brand Egg para que lo revises.'
                        : toItem
                          ? 'Guardado en el entregable.'
                          : 'Puesto en el Brand Egg para que lo revises.',
                );
            } catch {
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

        /*
         * ⚠️ "En los dos" IS FIRST BECAUSE IT IS THE COMMON CASE — what they
         * just said genuinely is the entregable AND material for the section.
         * It is still a choice: nothing saves without a click.
         */
        if (attrs.item) {
            button('En los dos', true, () => save(true, attrs.item));
            button('Sólo en el Brand Egg', false, () => save(true, null));
            button('Sólo en el entregable', false, () => save(false, attrs.item));
        } else {
            button('Guardar en el Brand Egg', true, () => save(true, null));
        }

        button('Descartar', false, () => done('Descartado.'));

        wrap.append(actions);

        return wrap;
    };

    const addTurn = (who, text) => {
        const row = document.createElement('div');
        row.className = `egg-turn egg-turn-${who}`;

        if (who === 'assistant') {
            parse(text).forEach((part) => {
                if (part.kind === 'card') {
                    row.append(card(part));

                    return;
                }

                const prose = document.createElement('div');
                renderReply(prose, part.text);
                row.append(prose);
            });
        } else {
            row.textContent = text;
        }

        thread.append(row);
        row.scrollIntoView({ block: 'nearest' });
    };

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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const message = field.value.trim();

        if (!message) return;

        addTurn('user', message);
        composer.reset();

        const waiting = document.createElement('div');
        waiting.className = 'egg-turn egg-turn-assistant egg-turn-waiting';
        waiting.textContent = 'Pensando…';
        thread.append(waiting);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ message, layer: currentLayer() }),
            });

            const data = await response.json();
            waiting.remove();

            if (!response.ok) {
                addTurn('assistant', data.error ?? 'No obtuve respuesta.');

                return;
            }

            if (data.checklist) renderReply(checklist, data.checklist);

            addTurn('assistant', data.reply);
        } catch {
            waiting.remove();
            addTurn('assistant', 'No se pudo contactar al asistente.');
        }
    });

    // Switching rings asks for that section's checklist without spending a turn.
    panel.querySelectorAll('[data-egg-layer]').forEach((radio) => {
        radio.addEventListener('change', () => {
            checklist.replaceChildren();
        });
    });
}
