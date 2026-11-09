import { renderReply } from './assistant-text.js';
import { drawTurnAttachments } from './turn-attachments.js';

/**
 * The history side panel — item 5.
 *
 * ⚠️ IT DRIVES THE THREAD, IT IS NOT A SECOND COPY OF IT. Clicking a
 * conversation replaces what is in the panel assistant.js already owns, through
 * the same renderReply() a live answer goes through — so a turn read today
 * looks like the same turn read tomorrow (CLAUDE.md §7). Rendering history its
 * own way is how two formatters start disagreeing.
 *
 * ⚠️ ONE MODULE FOR BOTH SURFACES. The dashboard's panel and Brandy's are the
 * same markup behind the same [data-assistant], so this attaches to whichever
 * is on the page and takes its endpoints from a data attribute. Which side it
 * is on is the server's business, never a decision made here.
 *
 * The caller (assistant.js) gets back:
 *   currentId()      what to post with the next question
 *   noteReply(id,t)  the answer came back; the conversation may be new or
 *                    may have just been named by its first question
 *   refresh()        redraw the list from the server
 */
export function attachConversations({ root, thread, onLoad } = {}) {
    const panel = document.querySelector('[data-conversations]');

    // No panel on this screen is a perfectly good answer: the assistant works
    // exactly as it did before, posting no conversation and being answered in
    // whichever one the server resolves.
    if (!panel || !root) return null;

    const base = panel.dataset.base;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const body = panel.querySelector('[data-conversations-body]');
    const archivedBox = panel.querySelector('[data-conversations-archived]');
    const newButton = panel.querySelector('[data-conversations-new]');
    const newFolderButton = panel.querySelector('[data-conversations-new-folder]');
    const toggle = panel.querySelector('[data-conversations-toggle]');

    const meter = document.querySelector('[data-conversation-meter]');
    const meterFill = meter?.querySelector('[data-conversation-meter-fill]');
    const meterNote = meter?.querySelector('[data-conversation-meter-note]');
    const foldNow = meter?.querySelector('[data-conversation-summarise]');
    const summaryBox = meter?.querySelector('[data-conversation-summary]');
    const summaryText = meter?.querySelector('[data-conversation-summary-text]');
    const summarySave = meter?.querySelector('[data-conversation-summary-save]');

    let current = null;
    let rows = { folders: [], conversations: [], archived: [] };

    /* ------------------------------------------------------------------
       Talking to the server
       ------------------------------------------------------------------ */

    const call = async (path, { method = 'GET', payload = null } = {}) => {
        const response = await fetch(`${base}${path}`, {
            method,
            headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
                ...(payload ? { 'Content-Type': 'application/json' } : {}),
            },
            body: payload ? JSON.stringify(payload) : undefined,
        });

        // ⚠️ A 404 HERE IS THE SCOPING WORKING, not a bug to paper over. The
        // server refuses anything that is not this person's, on this side, in
        // this brand — so the honest thing is to redraw from the server rather
        // than keep showing a row it just said does not exist.
        if (!response.ok) {
            await refresh();
            throw new Error(`conversations: ${response.status}`);
        }

        return response.json().catch(() => ({}));
    };

    /* ------------------------------------------------------------------
       Drawing the list
       ------------------------------------------------------------------ */

    /** One conversation, as a row that can be clicked, dragged and menued. */
    const drawRow = (conversation) => {
        const row = document.createElement('div');
        row.className = 'conversations-row';
        row.draggable = true;
        row.dataset.id = conversation.id;
        row.classList.toggle('is-current', conversation.id === current);

        const name = document.createElement('button');
        name.type = 'button';
        name.className = 'conversations-name';
        name.textContent = conversation.title;
        name.title = conversation.title;
        name.addEventListener('click', () => open(conversation.id));

        const when = document.createElement('span');
        when.className = 'conversations-when';
        when.textContent = conversation.at ?? '';

        // The one hint that a conversation has been folded up. Without it the
        // meter is the only place it is ever said, and the meter only speaks
        // about whichever conversation is open.
        if (conversation.summarised) {
            const folded = document.createElement('span');
            folded.className = 'conversations-folded';
            folded.title = 'Tiene un resumen de lo anterior';
            folded.textContent = '·';
            when.prepend(folded);
        }

        const menu = document.createElement('button');
        menu.type = 'button';
        menu.className = 'conversations-menu-button';
        menu.setAttribute('aria-label', `Opciones de ${conversation.title}`);
        menu.textContent = '⋯';
        menu.addEventListener('click', (event) => {
            event.stopPropagation();
            openMenu(event, conversation);
        });

        row.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            openMenu(event, conversation);
        });

        row.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('text/plain', String(conversation.id));
            event.dataTransfer.effectAllowed = 'move';
            row.classList.add('is-dragging');
        });

        row.addEventListener('dragend', () => row.classList.remove('is-dragging'));

        row.append(name, when, menu);

        return row;
    };

    /** Anything a conversation can be dropped onto: a folder, or the loose list. */
    const acceptsDrop = (element, folderId) => {
        element.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            element.classList.add('is-dropping');
        });

        element.addEventListener('dragleave', () => element.classList.remove('is-dropping'));

        element.addEventListener('drop', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            element.classList.remove('is-dropping');

            const id = Number(event.dataTransfer.getData('text/plain'));
            if (!id) return;

            // ⚠️ `folder: null` is "take it out", and it has to be sent
            // explicitly — the server treats an ABSENT folder as "leave it
            // where it is", or renaming would unfile everything.
            await call(`/${id}`, { method: 'PATCH', payload: { folder: folderId } });
            await refresh();
        });
    };

    const draw = () => {
        body.replaceChildren();

        const loose = rows.conversations.filter((c) => c.folder === null);

        for (const folder of rows.folders) {
            const held = rows.conversations.filter((c) => c.folder === folder.id);

            const group = document.createElement('details');
            group.className = 'conversations-folder';
            group.open = true;

            const head = document.createElement('summary');
            head.className = 'conversations-folder-head';
            head.textContent = `${folder.name} (${held.length})`;
            head.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                openFolderMenu(event, folder);
            });

            group.append(head);
            held.forEach((c) => group.append(drawRow(c)));

            acceptsDrop(group, folder.id);
            body.append(group);
        }

        const unfiled = document.createElement('div');
        unfiled.className = 'conversations-loose';
        loose.forEach((c) => unfiled.append(drawRow(c)));
        acceptsDrop(unfiled, null);
        body.append(unfiled);

        if (!rows.conversations.length) {
            const empty = document.createElement('p');
            empty.className = 'conversations-empty';
            empty.textContent = 'Todavía no hay conversaciones guardadas.';
            body.append(empty);
        }

        archivedBox.replaceChildren();
        rows.archived.forEach((c) => archivedBox.append(drawRow(c)));

        drawMeter();
    };

    /* ------------------------------------------------------------------
       Copying a reference
       ------------------------------------------------------------------
       ⚠️ navigator.clipboard NEEDS A SECURE CONTEXT — https, or localhost.
       This app is served over https in production and over plain http on
       breakfast.test, so on the machine where most of this gets tried the API
       is simply not there. Failing silently would look like a broken menu
       item, so the fallback shows the text selected in a prompt, where Ctrl+C
       still works.
       ------------------------------------------------------------------ */

    const copy = async (text, what) => {
        try {
            await navigator.clipboard.writeText(text);

            // Said, because a copy that works leaves no trace on screen and a
            // copy that failed looks exactly the same.
            flash(`${what} copiada`);
        } catch {
            window.prompt(`Copia ${what.toLowerCase()} con Ctrl+C:`, text);
        }
    };

    /** A line that says what just happened, and then stops saying it. */
    const flash = (text) => {
        let note = panel.querySelector('.conversations-flash');

        if (!note) {
            note = document.createElement('p');
            note.className = 'conversations-flash';
            note.setAttribute('role', 'status');
            panel.querySelector('.conversations-head').after(note);
        }

        note.textContent = text;
        clearTimeout(note.dataset.timer);
        note.dataset.timer = setTimeout(() => note.remove(), 2400);
    };

    /* ------------------------------------------------------------------
       The menu
       ------------------------------------------------------------------
       Built and thrown away per click rather than kept hidden in the DOM: one
       menu that is moved around ends up open over the wrong row.
       ------------------------------------------------------------------ */

    const closeMenu = () => document.querySelector('.conversations-menu')?.remove();

    const buildMenu = (event, items) => {
        closeMenu();

        const menu = document.createElement('div');
        menu.className = 'conversations-menu';
        menu.style.top = `${event.clientY}px`;
        menu.style.left = `${event.clientX}px`;

        for (const [label, action] of items) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.addEventListener('click', async () => {
                closeMenu();
                await action();
            });
            menu.append(button);
        }

        document.body.append(menu);

        // One dismissal, on the next click anywhere. `once` so the listener
        // cannot pile up behind a menu that was already closed.
        setTimeout(() => document.addEventListener('click', closeMenu, { once: true }), 0);
    };

    const openMenu = (event, conversation) => buildMenu(event, [
        /*
         * ⚠️ TWO DIFFERENT THINGS, and the labels have to keep them apart.
         *
         * The REFERENCE is for pasting into a question — the server finds
         * #conv-N in what was asked and attaches that conversation's own
         * memory to the turn. The LINK is for opening it: an ordinary URL that
         * reopens the panel on this row.
         *
         * Somebody who pastes a link into the composer gets nothing, which is
         * why "referencia" is first and says what it is for.
         */
        ['Copiar referencia', () => copy(
            `#conv-${conversation.id}`,
            'La referencia',
        )],

        ['Copiar enlace', () => copy(
            `${window.location.origin}${window.location.pathname}?conversacion=${conversation.id}`,
            'El enlace',
        )],

        ['Renombrar', async () => {
            const title = window.prompt('Nombre de la conversación', conversation.title);
            if (title === null) return;

            await call(`/${conversation.id}`, { method: 'PATCH', payload: { title } });
            await refresh();
        }],

        [conversation.archived ? 'Desarchivar' : 'Archivar', async () => {
            await call(`/${conversation.id}`, {
                method: 'PATCH',
                payload: { archived: !conversation.archived },
            });
            await refresh();
        }],

        ['Borrar', async () => {
            // ⚠️ ASKED, and deliberately not a prettier confirmation. "Borrar"
            // sits one row under "Archivar" in this menu, and the two are a
            // slip apart. The delete is soft on the server, but nothing on
            // screen says so, so the question is the only warning there is.
            if (!window.confirm(`¿Borrar «${conversation.title}»?`)) return;

            await call(`/${conversation.id}`, { method: 'DELETE' });

            if (conversation.id === current) {
                current = null;
                thread.replaceChildren();
            }

            await refresh();
        }],
    ]);

    const openFolderMenu = (event, folder) => buildMenu(event, [
        ['Nueva conversación aquí', async () => {
            const made = await call('', { method: 'POST', payload: { folder: folder.id } });
            await refresh();
            await open(made.id);
        }],

        ['Renombrar carpeta', async () => {
            const name = window.prompt('Nombre de la carpeta', folder.name);
            if (name === null) return;

            await call(`/carpetas/${folder.id}`, { method: 'PATCH', payload: { name } });
            await refresh();
        }],

        ['Borrar carpeta', async () => {
            // Said plainly, because it is the one thing people fear here and
            // the answer is reassuring: the server's foreign key is
            // nullOnDelete, so nothing inside is lost.
            if (!window.confirm(
                `¿Borrar la carpeta «${folder.name}»? Las conversaciones que tiene dentro no se borran.`,
            )) return;

            await call(`/carpetas/${folder.id}`, { method: 'DELETE' });
            await refresh();
        }],
    ]);

    /* ------------------------------------------------------------------
       Opening one
       ------------------------------------------------------------------ */

    const open = async (id) => {
        const data = await call(`/${id}`);

        current = data.id;

        thread.replaceChildren();

        for (const turn of data.messages) {
            const line = document.createElement('div');
            line.className = `assistant-message assistant-message-${turn.role === 'assistant' ? 'assistant' : 'user'}`;
            // The same formatter a live reply gets — one implementation, or
            // history and the present would look like two different people.
            renderReply(line, turn.body);

            if (turn.attachments?.length) {
                drawTurnAttachments(line, turn.attachments.map((name) => ({ name })));
            }

            thread.append(line);
        }

        thread.scrollTop = thread.scrollHeight;

        summaryText && (summaryText.value = data.summary ?? '');

        draw();
        onLoad?.(data);
    };

    /* ------------------------------------------------------------------
       The meter
       ------------------------------------------------------------------
       ⚠️ CHARACTERS, NOT TOKENS, AND THE SERVER COUNTS THEM. Tokens only
       arrive in the ledger AFTER a request, so a token meter is always one
       turn stale and cannot move while somebody is typing. See
       App\Services\Ai\ConversationBudget, which is the one thing that decides
       this — this only draws what it was told.
       ------------------------------------------------------------------ */

    const drawMeter = () => {
        if (!meter) return;

        const row = [...rows.conversations, ...rows.archived].find((c) => c.id === current);

        meter.hidden = !row;
        if (!row) return;

        meterFill.style.width = `${row.percent}%`;
        meter.classList.toggle('is-full', row.warn);

        meterNote.textContent = row.warn
            ? `Esta conversación va por el ${row.percent}%. Cuando se llene, lo más antiguo pasa a un resumen.`
            : `${row.percent}% de la conversación`;

        if (summaryBox) summaryBox.hidden = !row.summarised;
    };

    foldNow?.addEventListener('click', async () => {
        if (!current) return;

        foldNow.disabled = true;

        try {
            const data = await call(`/${current}/resumir`, { method: 'POST' });
            if (summaryText) summaryText.value = data.summary ?? '';
            await refresh();
        } finally {
            foldNow.disabled = false;
        }
    });

    summarySave?.addEventListener('click', async () => {
        if (!current) return;

        await call(`/${current}/resumen`, {
            method: 'PATCH',
            payload: { summary: summaryText.value },
        });

        await refresh();
    });

    /* ------------------------------------------------------------------
       Wiring
       ------------------------------------------------------------------ */

    const refresh = async () => {
        const response = await fetch(base, { headers: { Accept: 'application/json' } });

        if (!response.ok) return;

        rows = await response.json();
        draw();
    };

    newButton?.addEventListener('click', async () => {
        const made = await call('', { method: 'POST' });
        current = made.id;
        thread.replaceChildren();
        await refresh();
    });

    newFolderButton?.addEventListener('click', async () => {
        const name = window.prompt('Nombre de la carpeta');
        if (!name) return;

        await call('/carpetas', { method: 'POST', payload: { name } });
        await refresh();
    });

    /*
     * Minimised or not, remembered per browser.
     *
     * localStorage because it is exactly what it is for: a per-viewer
     * convenience that may come back empty and must not matter when it does.
     * Wrapped, because it throws outright in a private window rather than
     * returning nothing.
     */
    const REMEMBER = 'breakfast.conversations.open';

    /*
     * ⚠️ IT MUST MATCH THE MEDIA QUERY IN conversations.css. Below it the panel
     * is a band above the content; above it, a rail down the right edge. Two
     * places know the number because one of them is a stylesheet and the other
     * has to ask a question CSS cannot answer.
     */
    const NARROW = '(max-width: 60rem)';

    const apply = (open) => {
        panel.classList.toggle('is-collapsed', !open);
        toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const setOpen = (open) => {
        apply(open);

        try {
            localStorage.setItem(REMEMBER, open ? '1' : '0');
        } catch { /* private window, or storage refused. It is only a preference. */ }
    };

    /*
     * ⚠️ THE DEFAULT DEPENDS ON THE VIEWPORT; THE REMEMBERED CHOICE DOES NOT.
     * On a narrow screen the panel is a band ABOVE the content, so leaving it
     * open pushes the assistant down the page before anyone has asked
     * anything; minimised it is one row that says Conversaciones. On the wide
     * rail it costs a column that is there either way, so it stays open.
     *
     * ⚠️ AND THE DEFAULT IS NOT WRITTEN DOWN — apply(), never setOpen(). The
     * moment it is stored it stops being "this is a phone" and becomes "this
     * person chose minimised", which would then follow them into a wide window
     * that nobody asked to collapse. Only the button writes.
     */
    let stored = null;

    try {
        stored = localStorage.getItem(REMEMBER);
    } catch { /* see above */ }

    apply(stored === null ? ! window.matchMedia(NARROW).matches : stored === '1');

    toggle?.addEventListener('click', () => {
        setOpen(panel.classList.contains('is-collapsed'));
    });

    /*
     * Opened from a link.
     *
     * ⚠️ AFTER the first refresh, not instead of it: open() draws the row as
     * current, and it can only do that once the list it is drawing exists.
     *
     * The parameter is then dropped from the address bar, so a reload does not
     * yank somebody back to a conversation they have since moved on from — the
     * link did its job the moment it was followed.
     */
    refresh().then(() => {
        const asked = Number(new URLSearchParams(window.location.search).get('conversacion'));

        if (!asked) return;

        // A 404 here is the scoping working — somebody else's link, or another
        // brand's. call() redraws and throws; there is nothing to add.
        open(asked).catch(() => {});

        const url = new URL(window.location.href);
        url.searchParams.delete('conversacion');
        window.history.replaceState({}, '', url);
    });

    return {
        currentId: () => current,

        /**
         * The answer landed.
         *
         * ⚠️ THE ID COMES BACK FROM THE SERVER because the first question of a
         * session may have just created the conversation — the panel had
         * nothing to send, and it is the reply that says where the turn went.
         * The title comes with it for the same reason: it was written from that
         * same question.
         */
        noteReply: (id) => {
            if (id) current = id;
            refresh();
        },

        refresh,
    };
}
