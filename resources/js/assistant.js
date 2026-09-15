import { AssistantOrb } from './assistant-orb.js';
import { drawTurnAttachments } from './turn-attachments.js';
import { recordingSupported, startRecording } from './assistant-recorder.js';
import { renderReply } from './assistant-text.js';
import { explain, explainNetwork } from './assistant-error.js';
import { attachComposer } from './assistant-composer.js';

/**
 * The assistant on the Breakfast home screen — every brand at once.
 *
 * The counterpart to process-assistant.js, which is scoped to one brand and
 * proposes entregable content. This one answers questions about the state of
 * the business, and it is READ-ONLY: it reports, it never changes anything.
 *
 * TWO KINDS OF QUESTION, and the brand picker is the switch. Its default,
 * "Todas las marcas", is the usual one and reads a portfolio snapshot built
 * from the database — never the brands' documents, which would be ruinous and
 * would answer none of these questions. Picking a brand appends that brand's
 * ficha without dropping the rest, because "¿cómo va X comparada con las
 * demás?" names one brand and still needs the others.
 *
 * See App\Services\Ai\AdminAssistant and portal/docs/asistente-admin.md.
 */

const root = document.querySelector('[data-assistant]');

if (root) {
    const canvas = root.querySelector('[data-orb]');
    const thread = root.querySelector('[data-assistant-thread]');
    const form = root.querySelector('[data-assistant-form]');
    const input = form?.querySelector('[name="question"]');
    const brand = form?.querySelector('select[name="brand"]');

    const orb = canvas
        ? new AssistantOrb(canvas, {
            color: getComputedStyle(document.body).getPropertyValue('--orb-line').trim() || '#ECBB12',
        })
        : null;

    /*
     * Replies are formatted by assistant-text.js: bold where the model wrote
     * bold, and our own routes as links.
     *
     * There and not in the prompt for three reasons: the prompt is block 1 of
     * a cached prefix and editing it for formatting is a cost with no answer
     * attached; a model asked politely for a format complies most of the time,
     * which is the worst kind of most; and Brandy's panel needs exactly the
     * same treatment, so it is one file rather than the same rules twice.
     */

    /** Add a turn to the thread and keep the newest one in view. */
    const say = (author, text) => {
        const line = document.createElement('div');
        line.className = `assistant-message assistant-message-${author}`;
        renderReply(line, text);
        thread.append(line);
        thread.scrollTop = thread.scrollHeight;

        return line;
    };

    /*
     * The conversation from last time, rendered by the server.
     *
     * It arrives as plain text, so the asterisks and the routes in it are
     * inert until they go through the same formatter a live reply gets — one
     * implementation, or a reply read today would look different from the
     * same reply read tomorrow.
     *
     * Then jump to the end: a thread that opens at the top makes somebody
     * scroll to find out where they left off.
     */
    for (const line of thread.querySelectorAll('[data-assistant-message]')) {
        renderReply(line, line.textContent);
    }

    thread.scrollTop = thread.scrollHeight;

    // Auto-growing box, Enter to send, Shift+Enter for a new line — and none
    // of it on a phone, where Return breaks the line. See assistant-composer.js.
    const composer = attachComposer(input, form);

    const endpoint = root.dataset.endpoint;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const send = form?.querySelector('button[type="submit"]');

    let busy = false;

    const setBusy = (state) => {
        busy = state;
        if (send) send.disabled = state;
        if (brand) brand.disabled = state;
    };

    /* ------------------------------------------------------------------
       What is going with the next question
       ------------------------------------------------------------------
       Images and voice notes, held here until the message is sent. Two at a
       time, matching the server: this is a chat, not the brandbook reader on
       the process board.
       ------------------------------------------------------------------ */

    const MAX_FILES = 2;
    const attached = [];

    const attachBox = root.querySelector('[data-assistant-attached]');
    const picker = root.querySelector('[data-assistant-file]');
    const recordButton = root.querySelector('[data-assistant-record]');

    const drawAttached = () => {
        if (!attachBox) return;

        attachBox.replaceChildren();

        attached.forEach((file, index) => {
            const chip = document.createElement('span');
            chip.className = 'assistant-chip';
            chip.append(file.name);

            const drop = document.createElement('button');
            drop.type = 'button';
            drop.className = 'assistant-chip-drop';
            drop.setAttribute('aria-label', `Quitar ${file.name}`);
            drop.textContent = '×';
            drop.addEventListener('click', () => {
                attached.splice(index, 1);
                drawAttached();
            });

            chip.append(drop);
            attachBox.append(chip);
        });
    };

    const addFiles = (files) => {
        for (const file of files) {
            if (attached.length >= MAX_FILES) {
                say('assistant', `Puedo con ${MAX_FILES} archivos por mensaje. Manda el resto en otro.`);
                break;
            }

            attached.push(file);
        }

        drawAttached();
    };

    /* --- three ways to hand her an image -------------------------------
       Pasting is the one people actually do: the gesture for "look at this"
       is a screenshot on the clipboard. Dropping and the picker are there
       because not everybody reaches for the same one.
       ------------------------------------------------------------------ */

    input?.addEventListener('paste', (event) => {
        const files = [...(event.clipboardData?.files ?? [])];

        if (!files.length || busy) return;

        // Stop the browser also pasting the image's filename as text.
        event.preventDefault();
        addFiles(files);
    });

    picker?.addEventListener('change', () => {
        addFiles([...picker.files]);
        // Cleared so picking the same file twice in a row still fires change.
        picker.value = '';
    });

    for (const type of ['dragenter', 'dragover']) {
        root.addEventListener(type, (event) => {
            event.preventDefault();
            root.classList.add('is-dropping');
        });
    }

    for (const type of ['dragleave', 'drop']) {
        root.addEventListener(type, (event) => {
            event.preventDefault();
            if (type === 'drop') addFiles([...(event.dataTransfer?.files ?? [])]);
            root.classList.remove('is-dropping');
        });
    }

    /* --- talking to her ------------------------------------------------- */

    let recorder = null;

    const setRecording = (on) => {
        recordButton?.classList.toggle('is-recording', on);
        recordButton?.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (recordButton) {
            recordButton.title = on ? 'Detener y adjuntar' : 'Grabar una nota de voz';
        }
    };

    recordButton?.addEventListener('click', async () => {
        if (busy) return;

        if (recorder) {
            const holding = recorder;
            recorder = null;
            setRecording(false);

            try {
                addFiles([await holding.stop()]);
            } catch (error) {
                say('assistant', 'No pude procesar la grabación. Inténtalo otra vez.');
            }

            return;
        }

        try {
            recorder = await startRecording();
            setRecording(true);
        } catch (error) {
            // Denied permission, no microphone, or an insecure context —
            // getUserMedia needs HTTPS or localhost.
            say('assistant', 'No pude usar el micrófono. Revisa los permisos del navegador.');
        }
    });

    // No MediaRecorder, no button: an affordance that cannot work is worse
    // than one that is not there.
    if (recordButton && !recordingSupported()) {
        recordButton.remove();
    }

    /**
     * Put a failed question back where somebody can send it again.
     *
     * ⚠️ THE TEXT COMES BACK, AND SO DO THE FILES. The beta review lost a typed
     * question to a provider hiccup: the field was cleared before the fetch, so
     * a failure left an error message and an empty box, and the only way to
     * retry was to type the whole thing again. Nothing was spent and nothing was
     * recorded on our side either, which makes losing it purely gratuitous.
     */
    const restore = (question, files, { errorLine, userLine }) => {
        input.value = question;
        // Re-fit the box: it was reset to one row on send.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        attached.push(...files);
        drawAttached();

        // The echoed question goes away with it. Nothing was recorded on the
        // server, so leaving it would put the same question in the thread twice
        // as soon as somebody retried — a transcript of something that never
        // happened.
        userLine?.remove();

        const again = document.createElement('button');
        again.type = 'button';
        again.className = 'assistant-retry';
        again.textContent = 'Reintentar';
        again.addEventListener('click', () => {
            again.remove();
            form?.requestSubmit();
        });

        errorLine.append(again);
    };

    async function ask(question) {
        if (busy) {
            return;
        }

        setBusy(true);

        // The files leave the tray with the message, so a failed send does not
        // silently re-attach them to the next one. They come back in restore()
        // if the turn fails.
        const files = attached.splice(0, attached.length);
        drawAttached();

        // ⚠️ THE FILES ARE DRAWN, NOT NAMED. This used to append
        // "[Adjunto: captura.png]" to the visible text — which the server never
        // renders, so the turn you just sent and the same turn after a reload
        // said two different things. Now both draw the file itself; see
        // turn-attachments.js, which mirrors the Blade component.
        const userLine = say('user', question);

        drawTurnAttachments(userLine, files);
        thread.scrollTop = thread.scrollHeight;

        // Cleared here rather than before the request: a failure puts it back.
        composer.reset();

        // Reading a dozen brands' worth of state is a real wait, and the orb is
        // the only thing on screen that says so.
        orb?.setState('pensando');

        try {
            /*
             * FormData when something is attached, JSON when nothing is.
             *
             * Not FormData always: the plain question is the overwhelming case
             * and JSON is what the endpoint has always taken. And never set
             * Content-Type by hand for FormData — the browser has to add the
             * multipart boundary, and naming the type strips it.
             */
            const body = files.length ? new FormData() : null;

            if (body) {
                body.append('question', question);
                // Empty value is "Todas las marcas" — the default, and the
                // mode most questions arrive in.
                if (brand?.value) body.append('brand', brand.value);
                files.forEach((file) => body.append('files[]', file));
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    ...(body ? {} : { 'Content-Type': 'application/json' }),
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: body ?? JSON.stringify({ question, brand: brand?.value || null }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                // Named, and with the status attached — see assistant-error.js.
                restore(question, files, {
                    errorLine: say('assistant', explain(response, data)),
                    userLine,
                });
                return;
            }

            orb?.setState('respondiendo');
            say('assistant', data.reply || 'No obtuve respuesta.');
        } catch (error) {
            restore(question, files, {
                errorLine: say('assistant', explainNetwork()),
                userLine,
            });
        } finally {
            setBusy(false);
            // Long enough to read the answering state, short enough not to feel
            // stuck.
            setTimeout(() => orb?.setState('reposo'), 2200);
        }
    }

    form?.addEventListener('submit', (event) => {
        event.preventDefault();

        const question = input?.value.trim();

        // A voice note IS the question. Requiring text alongside it defeats
        // the point of recording one, so an attachment on its own sends.
        if (question || attached.length) {
            ask(question ?? '');
        }
    });

    // The dashboard theme toggle repaints everything else; the orb has to be
    // told, or it keeps the colour of the theme you just left.
    if (orb) {
        new MutationObserver(() => {
            orb.setColor(getComputedStyle(document.body).getPropertyValue('--orb-line').trim());
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }
}
