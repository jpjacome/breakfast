/**
 * A reply, as nodes: bold where the model wrote bold, links where it named one
 * of our own routes.
 *
 * ONE IMPLEMENTATION FOR BOTH ASSISTANTS. Brandy on the process board and the
 * dashboard assistant are different agents with different context, but they are
 * one voice as far as anybody reading them is concerned, and a reply that came
 * out bold on one screen and starred on the other would say otherwise. Both
 * surfaces render their live replies AND their server-rendered history through
 * here, so a turn read today looks like the same turn read tomorrow.
 *
 * ⚠️ NODES, NEVER innerHTML. Everything below builds elements and text nodes,
 * so whatever the model wrote stays text — the point of formatting a reply is
 * the two marks we chose to honour, not handing a language model a way to put
 * markup on the page. The route pattern matches our own paths and nothing
 * else, so no reply can become a link off this site.
 *
 * WHY FORMAT AT ALL, rather than asking the prompt for plain text. Because the
 * prompt is block 1 of a cached prefix — editing it costs the cache and buys a
 * model that complies most of the time, which is the worst kind of most. The
 * models write markdown whatever they are told, so the honest fix is to read
 * it. Found live 2026-08-18: replies were arriving with **asterisks around
 * every emphasis** and showing them.
 *
 * ⚠️ HEADINGS, LISTS AND TABLES WERE DELIBERATELY NOT HANDLED, until they were.
 * The argument was that "- Tres colores" reads fine as text and honouring it
 * would mean inventing a list style for a panel that had none. The beta review
 * disagreed, and it was right: once Brandy is answering "compárame estas dos
 * marcas por etapa, avance y responsable", the answer IS a table, and a table
 * flattened into prose is unreadable. So the panel has those styles now — see
 * assistant.css — and this file reads the marks the models were already
 * writing.
 *
 * Still not handled, and still on purpose: blockquotes, images, horizontal
 * rules and links to anywhere but our own routes. Nothing asks for them, and
 * every one of them is a way for a language model to put something on the page
 * that nobody designed.
 */

/**
 * The inline marks, tried in this order.
 *
 * Bold before italic, because `**` would otherwise be read as an empty italic
 * and swallow the asterisks it was meant to own. Code last and matched on
 * backticks, which nothing else uses.
 */
const INLINE = [
    { pattern: /\*\*(.+?)\*\*/gs, tag: 'strong' },
    // A lone asterisk is more often a bullet, so italics take the underscore
    // form only — `_así_` — with a word boundary so snake_case survives.
    { pattern: /(?:^|(?<=[\s(¡¿"']))_([^_\n]+)_(?=$|[\s.,;:!?)"'])/g, tag: 'em' },
    { pattern: /`([^`\n]+)`/g, tag: 'code' },
];

/** One of our own paths, optionally in backticks: /admin/…, /portal/…. */
const ROUTE = /`?(\/(?:admin|portal)(?:\/[\w-]+)*\/?)`?/g;

/**
 * A run of plain text, with any route in it turned into an anchor.
 *
 * @returns {Array<Node>}
 */
function withRoutes(text) {
    const nodes = [];
    let index = 0;

    for (const match of text.matchAll(ROUTE)) {
        const [whole, path] = match;

        // A placeholder rather than a destination: "/admin/clientes/" in
        // "/admin/clientes/{slug}/proceso" would link to a truncated path that
        // goes somewhere real but not where the sentence meant.
        if (text[match.index + whole.length] === '{') {
            continue;
        }

        // Part of something longer, not a path of ours: the tail of
        // "https://otro-sitio.com/admin/x" is not our /admin/x. The href would
        // still be same-origin, so this is about not mangling the sentence
        // rather than about where the link goes.
        const before = text[match.index - 1];

        if (before !== undefined && /[\w.:/]/.test(before)) {
            continue;
        }

        nodes.push(document.createTextNode(text.slice(index, match.index)));

        const link = document.createElement('a');
        link.href = path;
        link.className = 'assistant-route';
        link.textContent = path;
        nodes.push(link);

        index = match.index + whole.length;
    }

    nodes.push(document.createTextNode(text.slice(index)));

    return nodes;
}

/**
 * One run of text with its inline marks applied, as a fragment.
 *
 * The marks are applied in INLINE order and each one recurses into what the
 * previous left behind, so **bold with `code` inside** comes out as both. The
 * routes are found last, inside whatever plain text survives, which is why a
 * path inside a bold phrase is still a link and why the marks cannot swallow
 * each other.
 */
export function formatted(text, from = 0) {
    const fragment = document.createDocumentFragment();

    if (from >= INLINE.length) {
        fragment.append(...withRoutes(text));

        return fragment;
    }

    const { pattern, tag } = INLINE[from];
    let index = 0;

    // A fresh regex each call: these are /g and therefore stateful, and a
    // shared lastIndex across recursive calls skips matches at random.
    for (const match of text.matchAll(new RegExp(pattern.source, pattern.flags))) {
        fragment.append(formatted(text.slice(index, match.index), from + 1));

        const marked = document.createElement(tag);
        // Code is verbatim by definition: a path inside backticks is being
        // shown, not offered as somewhere to click.
        if (tag === 'code') {
            marked.textContent = match[1];
        } else {
            marked.append(formatted(match[1], from + 1));
        }
        fragment.append(marked);

        index = match.index + match[0].length;
    }

    fragment.append(formatted(text.slice(index), from + 1));

    return fragment;
}

/**
 * A whole reply into an element, one <p> per paragraph.
 *
 * The blank lines are the model's other formatting mark, and they were being
 * dropped for the same reason the asterisks were showing: text appended to a
 * div is one run, and HTML collapses the newlines in it. A four-thought answer
 * arrived as a wall.
 *
 * Clears the element first, so it doubles as the way to re-render history the
 * server printed as text: renderReply(line, line.textContent).
 */
export function renderReply(element, text) {
    element.replaceChildren();

    for (const block of blocks(text)) {
        element.append(block);
    }
}

/** `# Título`, up to three levels. */
const HEADING = /^(#{1,3})\s+(.*)$/;

/** `- algo`, `* algo`, `• algo`. */
const BULLET = /^[-*•]\s+(.+)$/;

/** `1. algo`, `2) algo`. */
const NUMBERED = /^\d+[.)]\s+(.+)$/;

/** `| uno | dos |` — a table row, however ragged. */
const ROW = /^\s*\|(.+)\|\s*$/;

/** `|---|:--:|` — the line under a table's header, which is not data. */
const RULE = /^\s*\|[\s:|-]+\|\s*$/;

/**
 * A reply as block elements, in order.
 *
 * Line by line rather than paragraph by paragraph, because the marks that
 * matter here are per-line: a list is a run of lines that each start with a
 * dash, and a blank line between two of them does not end it. The old version
 * split on blank lines first, which is why a list could never have been
 * recognised without this being rewritten.
 *
 * Anything unrecognised falls through to a paragraph, exactly as before — the
 * point is to read the marks the models already write, not to demand them.
 *
 * @returns {Array<HTMLElement>}
 */
function blocks(text) {
    const lines = text.split('\n');
    const out = [];

    // The run being accumulated: a list's items, a table's rows, or the lines
    // of one paragraph. Flushed whenever the kind of line changes.
    let run = null;

    const flush = () => {
        if (run) out.push(run.build());
        run = null;
    };

    for (const raw of lines) {
        const line = raw.trim();

        if (line === '') {
            flush();
            continue;
        }

        const heading = line.match(HEADING);

        if (heading) {
            flush();
            // h3/h4/h5: the panel sits inside a page that already owns h1 and
            // h2, and a reply must not outrank the screen it is printed on.
            const tag = `h${Math.min(5, heading[1].length + 2)}`;
            const node = document.createElement(tag);
            node.append(formatted(heading[2]));
            out.push(node);
            continue;
        }

        if (RULE.test(line)) {
            // The header separator. It belongs to a table already open; on its
            // own it is not worth printing as anything.
            continue;
        }

        const row = line.match(ROW);

        if (row) {
            if (run?.kind !== 'table') {
                flush();
                run = tableRun();
            }
            run.add(row[1]);
            continue;
        }

        const bullet = line.match(BULLET);
        const numbered = line.match(NUMBERED);

        if (bullet || numbered) {
            const kind = bullet ? 'ul' : 'ol';

            if (run?.kind !== kind) {
                flush();
                run = listRun(kind);
            }
            run.add((bullet ?? numbered)[1]);
            continue;
        }

        if (run?.kind !== 'p') {
            flush();
            run = paragraphRun();
        }
        run.add(line);
    }

    flush();

    return out;
}

/** Consecutive `- ` or `1. ` lines as one list. */
function listRun(kind) {
    const items = [];

    return {
        kind,
        add: (text) => items.push(text),
        build() {
            const list = document.createElement(kind);

            for (const item of items) {
                const li = document.createElement('li');
                li.append(formatted(item));
                list.append(li);
            }

            return list;
        },
    };
}

/**
 * Consecutive `| … |` lines as one table.
 *
 * The first row is the header. Ragged rows are padded rather than refused: a
 * model that drops a trailing cell should cost a blank square, not the whole
 * table.
 *
 * The table is wrapped in its own scroller — a five-column comparison is wider
 * than the panel, and the page itself must never scroll sideways.
 */
function tableRun() {
    const rows = [];

    return {
        kind: 'table',
        add: (text) => rows.push(text.split('|').map((cell) => cell.trim())),
        build() {
            const wrap = document.createElement('div');
            wrap.className = 'assistant-table';

            const table = document.createElement('table');
            const width = Math.max(...rows.map((row) => row.length));
            const [head, ...body] = rows;

            const thead = document.createElement('thead');
            thead.append(buildRow(head, width, 'th'));
            table.append(thead);

            if (body.length) {
                const tbody = document.createElement('tbody');

                for (const row of body) {
                    tbody.append(buildRow(row, width, 'td'));
                }

                table.append(tbody);
            }

            wrap.append(table);

            return wrap;
        },
    };
}

function buildRow(cells, width, tag) {
    const tr = document.createElement('tr');

    for (let i = 0; i < width; i++) {
        const cell = document.createElement(tag);
        cell.append(formatted(cells[i] ?? ''));
        tr.append(cell);
    }

    return tr;
}

/** Consecutive plain lines as one paragraph. */
function paragraphRun() {
    const lines = [];

    return {
        kind: 'p',
        add: (text) => lines.push(text),
        build() {
            const p = document.createElement('p');
            // A single newline inside a paragraph is a wrap, not a break: the
            // models hard-wrap prose and honouring that would put ragged line
            // ends through the middle of a sentence.
            p.append(formatted(lines.join(' ')));

            return p;
        },
    };
}
