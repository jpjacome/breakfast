/*
 * The three ways somebody hands an assistant a file, and the chips that show
 * what is going with the next message.
 *
 * ONE IMPLEMENTATION, EVERY PANEL. This lived inline in assistant.js, which
 * meant the Brand Egg's panel had nothing to import and shipped with no way to
 * attach anything at all — the same shape of mistake as writing a second
 * composer for one screen. A file picked on the dashboard and one pasted into
 * the Egg must behave identically, because to the person using them they are
 * the same gesture.
 *
 * ⚠️ PASTING IS THE ONE PEOPLE ACTUALLY DO. The gesture for "look at this" is a
 * screenshot on the clipboard. Dropping and the picker are here because not
 * everybody reaches for the same one, not because they are equally used.
 *
 * ⚠️ IT OWNS THE LIST, NOT THE SENDING. The caller reads `files()` when it
 * posts and calls `clear()` afterwards — this module never knows about an
 * endpoint, which is why it can serve four panels that post to four of them.
 */

/** Per message. Two is what the providers take happily and what a person means. */
const MAX_FILES = 2;

/**
 * @param {HTMLElement} root       the panel, for drag-and-drop and the box
 * @param {HTMLTextAreaElement} input  the field, for paste
 * @param {object} options
 * @param {() => boolean} options.busy  true while a turn is in flight
 * @param {(message: string) => void} options.say  how to tell somebody the cap was hit
 * @returns {{files: () => File[], clear: () => void, add: (files: File[]) => void}}
 */
export function attachFiles(root, input, { busy = () => false, say = () => {} } = {}) {
    const attached = [];

    const attachBox = root.querySelector('[data-assistant-attached]');
    const picker = root.querySelector('[data-assistant-file]');

    const draw = () => {
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
                draw();
            });

            chip.append(drop);
            attachBox.append(chip);
        });
    };

    const add = (files) => {
        for (const file of files) {
            if (attached.length >= MAX_FILES) {
                say(`Puedo con ${MAX_FILES} archivos por mensaje. Manda el resto en otro.`);
                break;
            }

            attached.push(file);
        }

        draw();
    };

    input?.addEventListener('paste', (event) => {
        const files = [...(event.clipboardData?.files ?? [])];

        if (!files.length || busy()) return;

        // Stop the browser also pasting the image's filename as text.
        event.preventDefault();
        add(files);
    });

    picker?.addEventListener('change', () => {
        add([...picker.files]);
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
            if (type === 'drop') add([...(event.dataTransfer?.files ?? [])]);
            root.classList.remove('is-dropping');
        });
    }

    return {
        files: () => [...attached],
        clear: () => {
            attached.length = 0;
            draw();
        },
        add,
    };
}
