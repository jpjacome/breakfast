/**
 * Enlarge an image from a chat turn.
 *
 * A <dialog>, not a hand-built overlay: the browser gives the backdrop, the
 * Escape key, the focus trap and the return of focus for free, and every one of
 * those is a thing a hand-rolled modal gets wrong.
 *
 * ⚠️ IT ONLY EVER SHOWS OUR OWN ROUTES. The src comes from a data attribute the
 * server wrote for a file a person attached, never from anything a model
 * produced — see the note in turn-attachments.blade.php. Nothing here reads a
 * URL out of message text.
 *
 * One dialog for the whole page, reused. Building one per image would put a
 * hundred hidden copies of a long transcript into the DOM.
 */

let dialog = null;

function ensureDialog() {
    if (dialog) {
        return dialog;
    }

    dialog = document.createElement('dialog');
    dialog.className = 'lightbox';
    dialog.innerHTML = '';

    const figure = document.createElement('figure');

    const image = document.createElement('img');
    image.className = 'lightbox-image';

    const caption = document.createElement('figcaption');
    caption.className = 'lightbox-caption';

    figure.append(image, caption);
    dialog.append(figure);
    document.body.append(dialog);

    // Clicking the backdrop closes. The dialog element IS the backdrop, so a
    // click that lands on it rather than on the figure came from outside.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    // Let go of the bytes when it closes. A transcript full of large images
    // would otherwise keep the last one decoded for the life of the page.
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
    });

    return dialog;
}

function open(src, title) {
    const box = ensureDialog();

    box.querySelector('.lightbox-image').src = src;
    box.querySelector('.lightbox-image').alt = title ?? '';
    box.querySelector('.lightbox-caption').textContent = title ?? '';

    box.showModal();
}

/**
 * Delegated, because a transcript grows: the turn you just sent is appended
 * after this file ran, and a listener bound per button would never see it.
 */
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-lightbox]');

    if (!trigger || trigger.dataset.lightboxKind !== 'image') {
        return;
    }

    event.preventDefault();
    open(trigger.dataset.lightbox, trigger.dataset.lightboxTitle);
});
