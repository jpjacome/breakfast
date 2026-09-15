import { AssistantOrb, ORB_DEFAULTS, ORB_SHAPES, ORB_STATES } from './assistant-orb.js';

/**
 * Test bench for the assistant orb, mounted at /admin/orbe.
 *
 * Its own Vite entry rather than a few lines in app.js: app.js is the public
 * site's GSAP bundle and ships on every marketing page, and three.js has no
 * business being downloaded by someone reading the podcast page.
 *
 * The controls are generated from the spec below rather than written out in
 * the Blade, so a new tunable is one row here and appears with its slider, its
 * readout and its place in the exported block. The orb reads orb.settings every
 * frame, so a slider writes straight into the live object — nothing to apply.
 *
 * Delete this file, its route and its view once the orb is living inside the
 * real assistant panel.
 */

/**
 * label, path within a state's config, min, max, step.
 * A numeric index at the end of the path targets one slot of a pair.
 */
const CONTROLS = [
    { label: 'Giro X', path: ['spin', 0], min: 0, max: 1.5, step: 0.01 },
    { label: 'Giro Y', path: ['spin', 1], min: 0, max: 1.5, step: 0.01 },
    { label: 'Giro Z', path: ['spin', 2], min: 0, max: 1.5, step: 0.01 },
    { label: 'Respiración — amplitud', path: ['breath', 0], min: 0, max: 0.25, step: 0.005 },
    { label: 'Respiración — Hz', path: ['breath', 1], min: 0, max: 3, step: 0.02 },
    { label: 'Picos — amplitud', path: ['spike', 0], min: 0, max: 0.8, step: 0.01 },
    { label: 'Picos — Hz', path: ['spike', 1], min: 0, max: 4, step: 0.05 },
    { label: 'Opacidad', path: ['opacity'], min: 0.1, max: 1, step: 0.05 },
];

const GLOBALS = [
    { label: 'Tamaño', key: 'size', min: 0.4, max: 2, step: 0.05 },
    { label: 'Grosor de línea (px)', key: 'lineWidth', min: 0.5, max: 12, step: 0.5 },
    { label: 'Transición (ms)', key: 'transitionMs', min: 100, max: 2500, step: 50 },
];

const canvas = document.querySelector('[data-orb]');

if (canvas) {
    // The line colour is the page's, not the orb's: admin.css defines
    // --orb-line per theme, so the wireframe follows the dashboard instead of
    // carrying a hardcoded yellow that disappears on the light ground.
    const lineColor = () =>
        getComputedStyle(document.body).getPropertyValue('--orb-line').trim() || '#ECBB12';

    const orb = new AssistantOrb(canvas, { color: lineColor() });

    // Bench affordance: reach it from the console to try something the sliders
    // do not cover — orb.lines.rotation.set(...) to judge a shape from another
    // angle, orb.settings to poke a value directly.
    window.orb = orb;

    const label = document.querySelector('[data-orb-state]');
    const buttons = [...document.querySelectorAll('[data-orb-set]')];
    const output = document.querySelector('[data-orb-output]');

    /* --- state switching ------------------------------------------------- */

    const show = (state) => {
        orb.setState(state);
        label && (label.textContent = state);
        buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.orbSet === state)));
        highlight(state);
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            stopCycle();
            show(button.dataset.orbSet);
        });
    });

    let cycle = null;
    const cycleButton = document.querySelector('[data-orb-cycle]');

    const stopCycle = () => {
        clearInterval(cycle);
        cycle = null;
        cycleButton?.setAttribute('aria-pressed', 'false');
    };

    cycleButton?.addEventListener('click', () => {
        if (cycle) {
            return stopCycle();
        }

        cycleButton.setAttribute('aria-pressed', 'true');

        let i = ORB_STATES.indexOf(orb.state);
        cycle = setInterval(() => show(ORB_STATES[++i % ORB_STATES.length]), 3000);
    });

    /* --- controls --------------------------------------------------------- */

    const panels = new Map();

    const read = (state, path) =>
        path.length === 1
            ? orb.settings.states[state][path[0]]
            : orb.settings.states[state][path[0]][path[1]];

    const write = (state, path, value) => {
        if (path.length === 1) {
            orb.settings.states[state][path[0]] = value;
        } else {
            orb.settings.states[state][path[0]][path[1]] = value;
        }
    };

    /** One labelled slider with a live readout of its own value. */
    const slider = ({ label, min, max, step }, initial, onInput) => {
        const row = document.createElement('label');
        row.className = 'orb-control';

        const name = document.createElement('span');
        name.textContent = label;

        const value = document.createElement('output');
        value.className = 'orb-control-value admin-numbers';
        value.textContent = format(initial);

        const input = document.createElement('input');
        Object.assign(input, { type: 'range', min, max, step, value: initial });

        input.addEventListener('input', () => {
            const v = parseFloat(input.value);
            value.textContent = format(v);
            onInput(v);
            print();
        });

        const head = document.createElement('span');
        head.className = 'orb-control-head';
        head.append(name, value);
        row.append(head, input);

        return row;
    };

    for (const state of ORB_STATES) {
        const panel = document.querySelector(`[data-orb-panel="${state}"]`);

        if (! panel) {
            continue;
        }

        panels.set(state, panel);

        // Shape is the one control that is not a number: it decides which of
        // the twelve-vertex arrangements the state sits in.
        const shapeRow = document.createElement('label');
        shapeRow.className = 'orb-control';

        const shapeHead = document.createElement('span');
        shapeHead.className = 'orb-control-head';
        shapeHead.innerHTML = '<span>Forma</span>';

        const select = document.createElement('select');
        select.innerHTML = ORB_SHAPES.map((s) => `<option value="${s}">${s}</option>`).join('');
        select.value = orb.settings.states[state].shape;
        select.addEventListener('change', () => {
            orb.settings.states[state].shape = select.value;
            print();
        });

        shapeRow.append(shapeHead, select);
        panel.append(shapeRow);

        for (const control of CONTROLS) {
            panel.append(
                slider(control, read(state, control.path), (v) => write(state, control.path, v)),
            );
        }
    }

    const globalPanel = document.querySelector('[data-orb-panel="global"]');

    if (globalPanel) {
        for (const control of GLOBALS) {
            globalPanel.append(
                slider(control, orb.settings[control.key], (v) => {
                    orb.settings[control.key] = v;
                }),
            );
        }
    }

    /* --- the point of the exercise ---------------------------------------- */

    /** The current settings, as the block to paste over ORB_DEFAULTS. */
    const print = () => {
        if (! output) {
            return;
        }

        const states = ORB_STATES.map((state) => {
            const c = orb.settings.states[state];

            return [
                `        ${state}: {`,
                `            shape: '${c.shape}',`,
                `            spin: [${c.spin.map(format).join(', ')}],`,
                `            breath: [${c.breath.map(format).join(', ')}],`,
                `            spike: [${c.spike.map(format).join(', ')}],`,
                `            opacity: ${format(c.opacity)},`,
                '        },',
            ].join('\n');
        }).join('\n');

        output.textContent = [
            'export const ORB_DEFAULTS = {',
            `    size: ${format(orb.settings.size)},`,
            `    lineWidth: ${format(orb.settings.lineWidth)},`,
            `    transitionMs: ${Math.round(orb.settings.transitionMs)},`,
            '    states: {',
            states,
            '    },',
            '};',
        ].join('\n');
    };

    document.querySelector('[data-orb-copy]')?.addEventListener('click', async (event) => {
        await navigator.clipboard.writeText(output.textContent);

        const button = event.currentTarget;
        const previous = button.textContent;
        button.textContent = 'Copiado';
        setTimeout(() => (button.textContent = previous), 1500);
    });

    document.querySelector('[data-orb-reset]')?.addEventListener('click', () => {
        orb.settings = structuredClone(ORB_DEFAULTS);
        orb.resetRotation();

        // Rebuilding the inputs is cheaper than tracking every one of them,
        // and this button is pressed by hand at most a few times a minute.
        panels.forEach((panel) => (panel.innerHTML = ''));

        if (globalPanel) {
            globalPanel.innerHTML = '';
        }

        window.location.reload();
    });

    /** Dim the panels that are not the state currently on screen. */
    const highlight = (state) => {
        panels.forEach((panel, key) => panel.classList.toggle('is-active', key === state));
    };

    // The Blade decides which state the orb boots in, so the label and the
    // pressed button are read off the orb rather than trusted from the markup.
    show(orb.state);
    print();

    // The theme toggle flips data-theme on <html>; the orb has to hear about it
    // or it keeps painting the colour of the theme you just left.
    new MutationObserver(() => orb.setColor(lineColor()))
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
}

/** Trailing zeros make a settings block look machine-written. */
function format(n) {
    return String(parseFloat(Number(n).toFixed(3)));
}
