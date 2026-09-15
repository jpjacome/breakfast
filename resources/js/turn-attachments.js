/**
 * The files on the turn you just sent, drawn before the page reloads.
 *
 * ⚠️ THIS MIRRORS resources/views/components/turn-attachments.blade.php, AND
 * THAT ONE IS CANONICAL. The server renders a turn from stored assets; this
 * renders the same turn from the File objects still in the browser, because
 * the ids do not exist until the request comes back. Two renderers for one
 * thing is a drift risk, and the mitigation is that they share class names and
 * that the reloaded version is the one to match when either changes.
 *
 * It is deliberately the smaller of the two: the composer accepts images, PDF
 * and audio and nothing else, so there is no video case here — a video reaches
 * a transcript only as a brand file, which is the server's side of this.
 *
 * ⚠️ Object URLs are revoked on pagehide, not when the element goes. They are
 * the src of something on screen for as long as the page lives, and revoking
 * one early replaces a thumbnail with a broken image — the exact failure this
 * whole item exists to remove.
 */

const objectUrls = [];

window.addEventListener('pagehide', () => {
    while (objectUrls.length) {
        URL.revokeObjectURL(objectUrls.pop());
    }
});

/** The tabler paperclip, inline: a chip in here cannot use a Blade icon. */
function paperclip() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');

    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute(
        'd',
        'M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5',
    );

    svg.append(path);

    return svg;
}

function imageItem(file) {
    const url = URL.createObjectURL(file);
    objectUrls.push(url);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'turn-file-open';
    button.dataset.lightbox = url;
    button.dataset.lightboxKind = 'image';
    button.dataset.lightboxTitle = file.name;

    const image = document.createElement('img');
    image.src = url;
    image.alt = file.name;

    const label = document.createElement('span');
    label.className = 'screen-reader-only';
    label.textContent = `Ampliar ${file.name}`;

    button.append(image, label);

    return button;
}

function chipItem(file) {
    const chip = document.createElement('span');
    chip.className = 'turn-file-chip';

    const name = document.createElement('span');
    name.textContent = file.name;

    chip.append(paperclip(), name);

    return chip;
}

/**
 * Append the attachment row to a turn that was just added to the thread.
 *
 * @param {HTMLElement} turn  the .assistant-message the files went with
 * @param {Array<File>} files
 */
export function drawTurnAttachments(turn, files) {
    if (!files?.length) {
        return;
    }

    const list = document.createElement('ul');
    list.className = 'turn-files';

    for (const file of files) {
        const item = document.createElement('li');

        const isImage = (file.type || '').startsWith('image/');

        item.className = `turn-file turn-file-${isImage ? 'image' : 'file'}`;
        item.append(isImage ? imageItem(file) : chipItem(file));

        list.append(item);
    }

    turn.append(list);
}
