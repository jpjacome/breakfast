import { AssistantOrb } from './assistant-orb.js';
import { drawTurnAttachments } from './turn-attachments.js';
import { formatted, renderReply } from './assistant-text.js';
import { explain, explainNetwork } from './assistant-error.js';
import { attachComposer } from './assistant-composer.js';

/**
 * The assistant above the entregables board.
 *
 * It has exactly one power: writing into the textareas on this page. It cannot
 * save, and there is no code path here that posts to brand_deliverables — a
 * person presses Guardar, or nothing was ever written down.
 *
 * EVERY proposal is a card, and a card does nothing until somebody clicks it.
 * There is no confidence threshold and nothing fills itself in: that is why
 * the table has no provenance column, because there is no state where the model
 * authored a value on its own and something would need to record it.
 *
 * Confidence is shown but decides nothing — the server sorts least-certain
 * first so the readings that most need a person's eyes are not at the bottom
 * of a list of forty.
 *
 * Counting what is filled belongs to process.js, not here. Applying a proposal
 * therefore fires a real 'input' event rather than calling into it: setting
 * .value in script does not fire one, and the counter would silently fall
 * behind the board.
 */

const root = document.querySelector('[data-brand-assistant]');
const form = document.querySelector('[data-process-form]');

if (root && form) {
    const endpoint = root.dataset.endpoint;
    const thread = root.querySelector('[data-thread]');
    const proposals = root.querySelector('[data-proposals]');
    const composer = root.querySelector('[data-composer]');
    const messageBox = composer.querySelector('[name="message"]');
    const fileInput = composer.querySelector('[data-files]');
    const attached = root.querySelector('[data-attached]');
    const thinking = root.querySelector('[data-thinking]');
    const sendButton = composer.querySelector('button[type="submit"]');

    // Auto-growing box, Enter to send, Shift+Enter for a new line — and Return
    // never sending on a phone. Shared with the two chat panels; see
    // assistant-composer.js.
    const messageField = attachComposer(messageBox, composer);

    // The same orb as the home screen, in its three states: idle while you
    // type, thinking while the model reads, answering as the reply lands.
    // It is the only thing on the page that shows the wait is a real one —
    // reading a forty-page brandbook is not a spinner's worth of time.
    const canvas = root.querySelector('[data-orb]');
    const orb = canvas
        ? new AssistantOrb(canvas, {
            color: getComputedStyle(document.body).getPropertyValue('--orb-line').trim() || '#ECBB12',
        })
        : null;

    // The theme toggle repaints everything else; the orb has to be told, or it
    // keeps the colour of the theme you just left.
    if (orb) {
        new MutationObserver(() => {
            orb.setColor(getComputedStyle(document.body).getPropertyValue('--orb-line').trim());
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    const fields = new Map(
        [...form.querySelectorAll('[data-entregable]')].map((el) => [el.dataset.entregable, el]),
    );

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    /* ------------------------------------------------------------------
       Writing into the board
       ------------------------------------------------------------------ */

    /**
     * Put a proposal into its textarea.
     *
     * mode 'replace' overwrites, 'append' adds underneath what is already
     * there. Appending is not a nicety: half these entregables are lists —
     * five values, four hex codes, a bank of ideas — and a second document
     * usually adds to them rather than contradicting them.
     */
    const applyProposal = (proposal, mode = 'replace') => {
        const textarea = fields.get(proposal.entregable);

        if (!textarea) return;

        const current = textarea.value.trim();

        textarea.value = mode === 'append' && current !== ''
            ? `${current}
${proposal.value}`
            : proposal.value;

        // Setting .value in script fires nothing, so the counter and the
        // filled/empty edge in process.js would never hear about this. One
        // real event keeps the two files from having to know about each other.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    };

    /* ------------------------------------------------------------------
       The two halves of a card: what is there now, and what is proposed
       ------------------------------------------------------------------ */

    /** What the field says today. Not editable here — the board below is where. */
    const readOnlyValue = (caption, text) => {
        const box = document.createElement('div');
        box.className = 'brand-proposal-value';

        const label = document.createElement('b');
        label.textContent = caption;
        box.append(label, document.createTextNode(text));

        return box;
    };

    /**
     * The proposed value, as a box you can type in before accepting it.
     *
     * A textarea for both entregables and brand details: a name is one line and
     * a relato is fifteen, and one control that grows covers both without the
     * card having to know which it is holding.
     *
     * onChange fires on every keystroke rather than on blur, so a value edited
     * and then accepted with the batch button — which never takes focus off the
     * textarea — carries the edit rather than the original reading.
     */
    const editableValue = (caption, text, onChange) => {
        const box = document.createElement('div');
        box.className = 'brand-proposal-value brand-proposal-value-editable';

        const label = document.createElement('b');
        label.textContent = caption;

        const field = document.createElement('textarea');
        field.className = 'brand-proposal-edit';
        field.rows = 1;
        field.value = text;
        field.setAttribute('aria-label', `${caption} — puedes editarlo antes de aplicarlo`);

        // Grows with what is in it. A fixed height either wastes a card on one
        // line or hides the end of a long reading behind an inner scrollbar,
        // and a proposal you cannot read whole is one you cannot judge.
        const fit = () => {
            field.style.height = 'auto';
            field.style.height = `${field.scrollHeight}px`;
        };

        field.addEventListener('input', () => {
            fit();
            onChange(field.value);
        });

        box.append(label, field);

        // After it is in the DOM, or scrollHeight is 0 and every box collapses.
        requestAnimationFrame(fit);

        return box;
    };

    /* ------------------------------------------------------------------
       The transcript
       ------------------------------------------------------------------ */

    /**
     * The little "who said this" line above a turn.
     *
     * ⚠️ Mirrors resources/views/components/turn-author.blade.php, AND THAT
     * ONE IS CANONICAL — same reason as turn-attachments.js: the server draws
     * the reloaded turn and the browser draws the one you just sent, so they
     * share class names and the Blade version is the one to match.
     */
    const byline = (author) => {
        const line = document.createElement('p');
        line.className = 'turn-author';

        const mark = document.createElement('span');
        mark.className = 'admin-avatar';
        mark.setAttribute('aria-hidden', 'true');

        const name = document.createElement('span');

        if (author === 'assistant') {
            mark.classList.add('turn-author-brandy');
            mark.textContent = 'B';
            name.textContent = 'Brandy';
        } else {
            mark.textContent = root.dataset.meInitials || '·';
            name.textContent = root.dataset.meName || 'Vos';
        }

        line.append(mark, name);

        return line;
    };

    const say = (author, text, files = []) => {
        const turn = document.createElement('div');
        turn.className = `assistant-message assistant-message-${author}`;

        // ⚠️ SIGNED, like the server signs it. This thread belongs to the
        // brand, so several people write into it and every turn says whose it
        // is — a live turn with no byline and a reloaded one with a byline are
        // the same turn looking like two. Mirrors x-turn-author.
        if (author === 'user' || author === 'assistant') {
            turn.append(byline(author));
        }

        // Paragraphs, bold and routes, through the same formatter the
        // dashboard assistant uses — see assistant-text.js. Nodes, never
        // markup: .textContent would print the model's asterisks.
        renderReply(turn, text);

        // ⚠️ Was a row of bare filenames in a <p class="brand-turn-files">,
        // which the server stopped rendering when the files started being kept:
        // the reloaded turn draws the picture, so the live one has to as well
        // or the same turn looks different before and after F5. See
        // turn-attachments.js, which mirrors the Blade component.
        drawTurnAttachments(turn, files);

        thread.append(turn);
        thread.scrollTop = thread.scrollHeight;

        return turn;
    };

    /**
     * Give a failed turn back to the person who typed it.
     *
     * ⚠️ THE MESSAGE AND THE FILES BOTH COME BACK. Re-picking four PDFs after a
     * provider hiccup is the kind of thing that makes somebody give up on the
     * screen — and on a read the server records the user's turn but not an
     * answer, so retyping would put the question in the thread twice.
     *
     * The echoed turn is removed for the same reason the dashboard removes its
     * own: a retry would repeat it.
     */
    const restore = (message, files, { errorLine, userLine }) => {
        messageBox.value = message;
        messageBox.dispatchEvent(new Event('input', { bubbles: true }));
        // addFiles() re-runs the size checks, which is right: the files are
        // being staged again exactly as if they had just been dropped.
        if (files.length) addFiles(files);
        userLine?.remove();

        const again = document.createElement('button');
        again.type = 'button';
        again.className = 'assistant-retry';
        again.textContent = 'Reintentar';
        again.addEventListener('click', () => {
            again.remove();
            composer.requestSubmit();
        });

        errorLine.append(again);
    };

    /*
     * The conversation from last time, printed by the server.
     *
     * It arrives as escaped text — it has to, it is a language model's output —
     * so its asterisks are inert until they come through the same formatter a
     * live reply gets. Without this pass a reply is bold when it lands and
     * starred the next time the screen is opened.
     *
     * Attachments need no exclusion: they render as a <ul class="turn-files">,
     * so a selector for paragraphs cannot reach them.
     */
    for (const paragraph of thread.querySelectorAll('.assistant-message > p')) {
        paragraph.replaceChildren(formatted(paragraph.textContent));
    }

    const askQuestions = (questions) => {
        if (!questions.length) return;

        const list = document.createElement('div');
        list.className = 'brand-questions';

        questions.forEach((question) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'brand-question';
            button.textContent = question;
            // Answering is the usual next move, so the question becomes the
            // prompt in the composer instead of something to retype.
            button.addEventListener('click', () => {
                messageBox.value = `${question}\n\n`;
                messageBox.focus();
                messageBox.setSelectionRange(messageBox.value.length, messageBox.value.length);
            });
            list.append(button);
        });

        thread.append(list);
        thread.scrollTop = thread.scrollHeight;
    };

    /* ------------------------------------------------------------------
       Proposals
       ------------------------------------------------------------------
       Every proposal is a card. Nothing is applied without a click, so an
       empty entregable and a written one differ only in whether there is a
       "before" worth showing.
       ------------------------------------------------------------------ */

    /**
     * Every card still waiting for a decision, keyed by the card itself.
     *
     * ONE map for both kinds — an entregable reading and a brand-detail
     * reading are the same thing to whoever is looking at the tray, and the
     * batch button has to count and apply both. Keyed by the element so
     * taking a card off, from any of its three buttons, is the same line.
     *
     * The map is the tray's truth, not the turn's response: the batch button
     * used to close over the list the server sent, so approving five cards by
     * hand left it saying "Aplicar las 20 propuestas" and pressing it wrote
     * back the fifteen — including the ones just dismissed with «Dejar como
     * está». What is pending is what is on screen.
     */
    const pending = new Map();

    /** Take a card off the tray, however it was decided, and recount. */
    const settle = (card) => {
        pending.delete(card);
        card.remove();
        paintBatch();
    };

    /**
     * Repaint the batch button from what is actually pending.
     *
     * Rebuilt rather than relabelled, so there is one place that decides
     * whether the button exists at all: with nothing left to decide it goes,
     * instead of standing there offering to re-apply an empty tray.
     */
    function paintBatch() {
        proposals.querySelector('[data-apply-all]')?.remove();

        if (pending.size === 0) return;

        // One click for the whole batch, for the case this was built for:
        // handing over a brandbook and getting twenty readings back. It is
        // still a person accepting — just not twenty times.
        const all = document.createElement('button');
        all.type = 'button';
        all.dataset.applyAll = '';
        all.className = 'admin-button admin-button-secondary admin-button-sm';
        all.textContent = pending.size === 1
            ? 'Aplicar la propuesta'
            : `Aplicar las ${pending.size} propuestas`;

        all.addEventListener('click', () => {
            // Copied first: each one removes itself from the map as it runs.
            // No scrolling — with twenty of them the page would chase the last
            // field applied.
            [...pending.values()].forEach((apply) => apply({ scroll: false }));
        });

        proposals.prepend(all);
    }

    const renderDecision = (proposal) => {
        const card = document.createElement('div');
        card.className = 'brand-proposal';

        const head = document.createElement('p');
        head.className = 'brand-proposal-head';
        head.innerHTML = `<span></span><span></span>`;
        head.children[0].textContent = proposal.label;
        head.children[1].textContent = `${Math.round(proposal.confidence * 100)}%`;
        card.append(head);

        const values = document.createElement('div');
        values.className = 'brand-proposal-values';

        // An empty entregable has no "before" to compare against, and an empty
        // box labelled "Ahora dice" would read as a bug.
        if (proposal.current) {
            values.append(readOnlyValue('Ahora dice', proposal.current));
        }

        // The proposal is EDITABLE. A reading is usually nearly right — the
        // wrong tense, a line that belongs in another entregable, one hex too
        // many — and applying it just to fix it in the board below means
        // leaving the card, losing the evidence line, and hunting for the field.
        // Editing here also keeps the meaning of the button honest: what you
        // are accepting is what you can see.
        //
        // Writes back into proposal.value, which is the same object the batch
        // button iterates, so an edit is picked up by "aplicar todo" as well.
        values.append(editableValue(
            'El asistente propone',
            proposal.value,
            (text) => { proposal.value = text; },
        ));

        card.append(values);

        if (proposal.evidence) {
            const evidence = document.createElement('p');
            evidence.className = 'admin-hint';
            evidence.textContent = `Fuente: ${proposal.evidence}`;
            card.append(evidence);
        }

        const actions = document.createElement('div');
        actions.className = 'brand-proposal-actions';

        /**
         * Registered below so the batch button can run it too.
         *
         * Same shape as the brand-detail one — {scroll} and nothing else — so
         * paintBatch() can iterate the tray without knowing what kind of card
         * it is holding.
         */
        const applyHere = ({ mode = 'replace', scroll = true } = {}) => {
            applyProposal(proposal, mode);

            if (scroll) {
                fields.get(proposal.entregable)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }

            settle(card);
        };

        pending.set(card, applyHere);

        const use = (mode, label, className) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `admin-button ${className} admin-button-sm`;
            button.textContent = label;
            button.addEventListener('click', () => applyHere({ mode }));

            return button;
        };

        const replace = use('replace', proposal.current ? 'Reemplazar' : 'Usar esto',
            'admin-button-primary');

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'admin-button admin-button-ghost admin-button-sm';
        dismiss.textContent = proposal.current ? 'Dejar como está' : 'Descartar';
        dismiss.addEventListener('click', () => settle(card));

        actions.append(replace);

        // Only where there is something to add to. On an empty entregable
        // "Agregar" and "Usar esto" would do the same thing, and two buttons
        // that do the same thing is a question nobody should have to answer.
        if (proposal.current) {
            actions.append(use('append', 'Agregar', 'admin-button-secondary'));
        }

        actions.append(dismiss);
        card.append(actions);

        proposals.append(card);
    };

    /**
     * The brand's own details — name, industry, contact — when the material
     * stated them.
     *
     * They target the clients table, not the board, so they write into the
     * inputs at the top of the form rather than a textarea. Same rule as
     * everything else: a card, and nothing happens until it is clicked. A name
     * read off page one of a brandbook is still a reading.
     *
     * Only rendered where those inputs exist, which is /clientes/nueva. On the
     * process screen the brand is already named and there is nothing to fill.
     */
    const brandLabels = {
        name: 'Nombre de la marca',
        industry: 'Industria',
        contact_name: 'Nombre de contacto',
        contact_email: 'Correo de contacto',
    };

    const renderBrandDetail = (key, value) => {
        const input = document.querySelector(`[data-draft-field][name="${key}"]`);

        if (!input || !brandLabels[key]) return;

        const current = input.value.trim();

        // Nothing to decide if it already says this.
        if (current.toLowerCase() === value.trim().toLowerCase()) return;

        // One card per field: a second reading of the name replaces the first
        // rather than stacking two cards proposing different names. Off the
        // tray as well as off the screen, or the count keeps counting it.
        const superseded = proposals.querySelector(`[data-brand-detail="${key}"]`);

        if (superseded) {
            pending.delete(superseded);
            superseded.remove();
        }

        const card = document.createElement('div');
        card.className = 'brand-proposal';

        // Survives the next turn, unlike an entregable card. Those describe a
        // board that has since moved on, so replacing them is right; this one
        // is a question still waiting for an answer — "¿la marca se llama
        // Patito?" is just as true after you have replied to something else.
        // Being wiped mid-conversation is how an approved name never reached
        // the field.
        card.dataset.brandDetail = key;

        const head = document.createElement('p');
        head.className = 'brand-proposal-head';
        head.innerHTML = '<span></span><span></span>';
        head.children[0].textContent = brandLabels[key];
        head.children[1].textContent = 'dato de la marca';
        card.append(head);

        const values = document.createElement('div');
        values.className = 'brand-proposal-values';

        if (current) {
            values.append(readOnlyValue('Ahora dice', current));
        }

        // Editable, like an entregable proposal. "The Coffee Club" read off a
        // cover is often "The Coffee Club S.A." on the invoice, and correcting
        // it here beats accepting the wrong one and fixing the field after.
        let accepted = value;

        values.append(editableValue('El asistente propone', value, (text) => {
            accepted = text;
        }));

        card.append(values);

        const actions = document.createElement('div');
        actions.className = 'brand-proposal-actions';

        /** Registered below so the batch button can run it too. */
        const applyValue = ({ scroll = true } = {}) => {
            // `accepted`, not `value`: what the person can see in the box is
            // what the button applies, edits included.
            input.value = accepted;
            // Real event, so the autosave hears about it — see client-draft.js.
            input.dispatchEvent(new Event('input', { bubbles: true }));

            if (scroll) input.scrollIntoView({ block: 'center', behavior: 'smooth' });

            settle(card);
        };

        pending.set(card, applyValue);

        const apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'admin-button admin-button-primary admin-button-sm';
        apply.textContent = current ? 'Reemplazar' : 'Usar esto';
        apply.addEventListener('click', () => applyValue());

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'admin-button admin-button-ghost admin-button-sm';
        dismiss.textContent = 'Descartar';
        dismiss.addEventListener('click', () => settle(card));

        actions.append(apply, dismiss);
        card.append(actions);

        proposals.append(card);
        paintBatch();
    };

    /**
     * @param list    the proposals that just arrived
     * @param clear   whether they replace the tray or land on top of it
     *
     * ⚠️ clear=false IS FOR THE BATCHES. An upload comes back as four calls,
     * twelve entregables each, and each one clearing the tray would leave only
     * the last twelve — three quarters of the reading thrown away in front of
     * somebody watching cards appear and vanish.
     */
    const handleProposals = (list, { clear = true } = {}) => {
        // Cards from the previous turn describe a board that has since changed.
        //
        // Entregable cards only. A brand-detail card is a pending question
        // about the brand's own fields, not a reading of a board that moved,
        // so it stays until somebody accepts or dismisses it.
        if (clear) {
            for (const card of [...proposals.children]) {
                if (card.dataset.brandDetail === undefined) {
                    pending.delete(card);
                    card.remove();
                }
            }
        }

        list.forEach(renderDecision);

        // Counts everything on the tray, not just this turn's entregables: a
        // brandbook states the brand's name and industry as much as it states
        // its colours, and the person pressing this means all of it.
        paintBatch();
    };

    /* ------------------------------------------------------------------
       Sending a turn
       ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------
       Attaching files: the picker, dropping, and pasting
       ------------------------------------------------------------------
       Three ways in, one list out. The picker alone was not enough — the
       gesture for handing somebody a brandbook is dragging it onto them, and
       the gesture for "look at this bit" is a screenshot on the clipboard.

       Everything funnels through addFiles(), so the accepted types, the cap
       and the preview are decided once. The server checks all of it again:
       this is the courtesy, not the guard.
       ------------------------------------------------------------------ */

    // Must match App\Services\Ai\Data\Attachment. A file that gets past here
    // only travels to the server to be refused, which is a slow way to say no.
    // The server checks all of this again; this copy exists for the speed of
    // the "no", not for the safety of the "yes".
    const ACCEPTED = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        'application/pdf',
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav',
        'audio/webm', 'audio/ogg', 'audio/flac', 'audio/aac',
    ];
    // ⚠️ TWO, and it must match 'files' => max:2 in BrandOnboardingTurnRequest.
    // Every file rides inside one request and is read in one generation, so
    // four is not a bigger batch — it is a single request holding a PHP worker
    // for minutes while this host answers the public site with 503s.
    const MAX_FILES = 2;

    /**
     * The caps, in bytes, mirroring App\Services\Ai\Data\Attachment.
     *
     * CHECKED HERE, BEFORE SENDING, on purpose. A file over PHP's
     * post_max_size never reaches Laravel at all: PHP discards the whole body,
     * the CSRF token goes with it, and the server answers 419 — which reads on
     * screen as "the assistant is unreachable". A 90 MB brandbook once sent
     * somebody hunting a connection problem that did not exist. Naming the size
     * here is instant and honest.
     *
     * 50MB for documents is Gemini's own PDF ceiling, not a number we chose;
     * 100MB is the inline limit for everything else. Keep these in step with
     * App\Services\Ai\Data\Attachment, which explains both.
     */
    const MAX_DOCUMENT_BYTES = 50 * 1024 * 1024;
    const MAX_AUDIO_BYTES = 100 * 1024 * 1024;

    const megabytes = (bytes) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;

    const tooBig = (file) => {
        const limit = file.type.startsWith('audio/') ? MAX_AUDIO_BYTES : MAX_DOCUMENT_BYTES;

        return file.size > limit ? limit : null;
    };

    /**
     * What will be sent with the next turn.
     *
     * The source of truth is this array, not the file input. The input reports
     * only the most recent pick, so reading it back would throw away anything
     * dropped or pasted beforehand — pick a file after dropping two and the
     * two would vanish. It is written to from here and never read from.
     */
    let staged = [];

    const syncFiles = () => {
        const carried = new DataTransfer();
        staged.forEach((file) => carried.items.add(file));
        fileInput.files = carried.files;

        attached.replaceChildren();
        attached.hidden = staged.length === 0;

        staged.forEach((file) => {
            const line = document.createElement('span');
            line.textContent = file.name;
            attached.append(line);
        });
    };

    const clearFiles = () => {
        staged = [];
        syncFiles();
    };

    /**
     * Add a drop, a paste or a pick to the list.
     *
     * Appends rather than replaces: handing over a second document should not
     * silently discard the first. Duplicates by name and size are dropped, so
     * dragging the same file twice does not send it twice.
     */
    const addFiles = (incoming) => {
        const seen = new Set(staged.map((file) => `${file.name}:${file.size}`));
        const rejected = [];
        const oversized = [];
        let overflowed = false;

        [...incoming].forEach((file) => {
            if (!ACCEPTED.includes(file.type)) {
                rejected.push(file.name);

                return;
            }

            const limit = tooBig(file);

            if (limit !== null) {
                oversized.push(`${file.name} (${megabytes(file.size)}, el máximo es ${megabytes(limit)})`);

                return;
            }

            const signature = `${file.name}:${file.size}`;

            if (seen.has(signature)) return;

            if (staged.length >= MAX_FILES) {
                overflowed = true;

                return;
            }

            seen.add(signature);
            staged.push(file);
        });

        syncFiles();

        if (rejected.length) {
            say('error', `Leo PDF, imágenes y audio. No puedo con: ${rejected.join(', ')}.`);
        }

        if (oversized.length) {
            say('error',
                `Ese archivo no me cabe: ${oversized.join('; ')}. `
                + 'Los archivos viajan dentro de la pregunta, así que hay un techo. '
                + 'Si es un brandbook pesado, exporta sólo las páginas que importan, '
                + 'compártelo comprimido, o súbelo como archivo de la marca y '
                + 'pégame aquí lo que quieras que lea.');
        }

        if (overflowed) {
            say('error', `Máximo ${MAX_FILES} archivos por mensaje. Los de más quedaron fuera.`);
        }
    };

    fileInput.addEventListener('change', () => {
        // Everything goes through addFiles() so the cap and the type check
        // apply to a manual pick exactly as they do to a drop.
        addFiles(fileInput.files);
    });

    /* --- dropping ---------------------------------------------------------
       The whole panel is the target, not a strip inside it. A drop zone you
       have to aim at is a drop zone people miss.
       --------------------------------------------------------------------- */

    // Nested elements fire dragleave as the pointer crosses them, so a plain
    // enter/leave pair flickers. Counting them is the standard fix.
    let dragDepth = 0;

    const carriesFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');

    const endDrag = () => {
        dragDepth = 0;
        root.classList.remove('is-dropping');
    };

    root.addEventListener('dragenter', (event) => {
        if (!carriesFiles(event) || busy) return;

        event.preventDefault();
        dragDepth += 1;
        root.classList.add('is-dropping');
    });

    root.addEventListener('dragover', (event) => {
        if (!carriesFiles(event) || busy) return;

        // Without this the browser navigates to the file instead of dropping it.
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });

    root.addEventListener('dragleave', () => {
        dragDepth -= 1;

        if (dragDepth <= 0) endDrag();
    });

    root.addEventListener('drop', (event) => {
        if (!carriesFiles(event)) return;

        event.preventDefault();
        endDrag();

        if (busy) return;

        addFiles(event.dataTransfer.files);
        messageBox.focus();
    });

    // A file dropped anywhere else would otherwise replace the whole page with
    // the PDF, which looks exactly like the app crashing.
    ['dragover', 'drop'].forEach((type) => {
        document.addEventListener(type, (event) => {
            if (carriesFiles(event) && !root.contains(event.target)) {
                event.preventDefault();
            }
        });
    });

    /* --- pasting ----------------------------------------------------------
       A screenshot on the clipboard has no filename, so it gets one: without
       it the transcript reads "[archivo adjunto: ]" forever after.
       --------------------------------------------------------------------- */

    messageBox.addEventListener('paste', (event) => {
        const pasted = [...(event.clipboardData?.files ?? [])];

        if (!pasted.length || busy) return;

        event.preventDefault();

        addFiles(pasted.map((file) => (
            file.name
                ? file
                : new File([file], `captura-${Date.now()}.png`, { type: file.type })
        )));
    });

    let busy = false;

    // The answering loop runs for a moment after the reply lands, then settles.
    // Held in a variable so a second question cancels it: without that, the
    // timer from the previous turn drops the orb to idle while it is thinking
    // about the next one.
    let settleTimer = null;

    const setBusy = (state) => {
        busy = state;
        thinking.hidden = !state;
        sendButton.disabled = state;
        fileInput.disabled = state;

        if (state) {
            clearTimeout(settleTimer);
            orb?.setState('pensando');
        }
    };

    /** Answer, hold it long enough to be seen, then go quiet. */
    const settleOrb = () => {
        if (!orb) return;

        orb.setState('respondiendo');
        clearTimeout(settleTimer);
        settleTimer = setTimeout(() => orb.setState('reposo'), 2200);
    };

    /**
     * What the waiting line says.
     *
     * It is the only thing on screen during a long turn, and a screen that says
     * nothing for ninety seconds is what teaches people to press send again —
     * which is the load this whole two-phase design exists to avoid. So it
     * counts out loud.
     */
    const progress = (text) => {
        thinking.textContent = text;
    };

    /** One POST to the assistant, decoded. */
    const post = async (body) => {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body,
        });

        return { response, data: await response.json().catch(() => ({})) };
    };

    /** The draft slug and the board, which every call in a turn has to carry. */
    const stateFields = (payload) => {
        const draftField = document.querySelector('[data-draft-slug]');

        if (draftField) payload.append('draft', draftField.value);

        // The board as it stands on screen, not as it was last saved: the
        // proposals for batch 3 must know what batch 1 has already filled in.
        fields.forEach((textarea, key) => payload.append(`form[${key}]`, textarea.value));

        return payload;
    };

    /**
     * The proposal calls that follow an upload, one slice of the board each.
     *
     * ⚠️ SEQUENTIAL, NOT PARALLEL, and not because the server cannot take two —
     * it can, the guard allows two in flight. Four at once would be four PHP
     * workers held by one person, which is the exact resource whose exhaustion
     * makes the public site answer 503. One at a time costs nothing but wall
     * clock, and the cards arriving in waves is better feedback than all of
     * them landing at the end anyway.
     */
    /**
     * Phase two: ask for the proposals a slice at a time.
     *
     * `turn` is the id of the assistant message the reading was announced on,
     * and every batch carries it so the server can keep the cards against that
     * turn — without it a reading lives only in this tab, and closing it throws
     * away forty-eight proposals somebody was halfway through accepting. See
     * BrandOnboardingController::keepProposals().
     */
    const runBatches = async (count, turn = null) => {
        for (let batch = 0; batch < count; batch++) {
            progress(`Proponiendo entregables… ${batch + 1} de ${count}`);

            const payload = stateFields(new FormData());
            payload.append('batch', String(batch));
            if (turn) payload.append('turn', String(turn));

            const { response, data } = await post(payload);

            if (!response.ok) {
                // Whatever landed already stays: three quarters of a reading is
                // worth more than a clean slate, and the person can see which
                // cards are there.
                say('error', explain(response, data));

                return;
            }

            handleProposals(data.proposals ?? [], { clear: false });
        }
    };

    composer.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy) return;

        const message = messageBox.value.trim();
        const files = [...staged];

        if (message === '' && files.length === 0) {
            messageBox.focus();
            return;
        }

        const payload = new FormData();
        payload.append('message', message);
        files.forEach((file) => payload.append('files[]', file));

        // /clientes/nueva only: the brand form beside the assistant, so a name
        // typed there names the draft instead of leaving it as the placeholder.
        // The slug and the board come from stateFields(), which every call in
        // the turn uses — including the proposal batches.
        if (document.querySelector('[data-draft-slug]')) {
            for (const field of document.querySelectorAll('[data-draft-field]')) {
                payload.append(`brand[${field.name}]`, field.value);
            }
        }

        stateFields(payload);

        // The File objects themselves, not their names: the renderer needs the
        // bytes to draw a thumbnail before the upload has an id.
        const userLine = say('user', message || 'Revisa los archivos adjuntos.', files);

        messageField.reset();
        clearFiles();

        setBusy(true);
        progress(files.length ? 'Leyendo los archivos…' : 'Pensando…');

        try {
            const { response, data } = await post(payload);

            if (!response.ok) {
                // Named, and with the status attached — see assistant-error.js.
                // A 503 here means the server killed the request, which is a
                // completely different problem from a refused file, and the two
                // used to read identically.
                restore(message, files, {
                    errorLine: say('error', explain(response, data)),
                    userLine,
                });

                return;
            }

            // The turn may have created the draft. Tell the page, so the
            // autosave and this panel keep naming the same row.
            if (data.draft) {
                document.dispatchEvent(new CustomEvent('draft:started', {
                    detail: { slug: data.draft, name: data.name },
                }));
            }

            if (data.reply) say('assistant', data.reply);

            // Brand details FIRST, then the entregables: handleProposals()
            // clears the tray of last turn's readings, and it has to leave
            // these standing — they are questions, not stale readings.
            for (const [key, value] of Object.entries(data.brand ?? {})) {
                renderBrandDetail(key, value);
            }

            handleProposals(data.proposals ?? []);
            askQuestions(data.questions ?? []);

            // An upload answers in two phases: what just came back is the
            // reading, and the proposals are the calls below. `batches` is 0
            // for a typed turn and for files that said nothing worth proposing
            // from, so this is also how "there is no phase two" is expressed.
            if (data.batches) {
                await runBatches(data.batches, data.turn ?? null);
            }
        } catch (error) {
            restore(message, files, {
                errorLine: say('error', explainNetwork()),
                userLine,
            });
        } finally {
            setBusy(false);
            progress('Leyendo…');
            settleOrb();
        }
    });

}
