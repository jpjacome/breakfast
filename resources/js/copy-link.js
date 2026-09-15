/**
 * Copy an asset's URL, so it can be pasted into the entregable it belongs to.
 *
 * An entregable that IS an asset holds this URL as its text, and typing it by
 * hand is how a wrong link gets saved.
 *
 * Its own entry because two screens grew the same button: the process board and
 * the file manager. One of them copying a link differently from the other is
 * the kind of drift nobody notices until a link is wrong.
 *
 * The button says what happened and goes back to itself. A dialog for a
 * clipboard write is more interruption than the action is worth, and the
 * execCommand fallback is there because clipboard.writeText needs a secure
 * context — which localhost has and a plain-http staging domain does not.
 */
for (const button of document.querySelectorAll('[data-copy-link]')) {
    button.addEventListener('click', async () => {
        const url = button.dataset.copyLink;
        const label = button.textContent;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(url);
            } else {
                const box = document.createElement('textarea');
                box.value = url;
                box.setAttribute('readonly', '');
                box.style.position = 'fixed';
                box.style.opacity = '0';
                document.body.append(box);
                box.select();
                document.execCommand('copy');
                box.remove();
            }

            button.textContent = 'Copiado';
            button.dataset.copied = 'true';
        } catch (error) {
            // Say so rather than pretending. The link is still on screen and
            // can be copied out of the address bar via Ver.
            button.textContent = 'No se pudo copiar';
        }

        setTimeout(() => {
            button.textContent = label;
            delete button.dataset.copied;
        }, 1800);
    });
}
