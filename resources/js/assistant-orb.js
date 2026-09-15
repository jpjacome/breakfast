import * as THREE from 'three';
import { LineSegments2 } from 'three/examples/jsm/lines/LineSegments2.js';
import { LineSegmentsGeometry } from 'three/examples/jsm/lines/LineSegmentsGeometry.js';
import { LineMaterial } from 'three/examples/jsm/lines/LineMaterial.js';

/**
 * The assistant's orb: one wireframe that morphs between three states.
 *
 * WHY THESE THREE SHAPES
 *
 * The three stages are one mesh, not three. Twelve vertices and thirty edges,
 * from first frame to last — what changes is where those twelve vertices sit.
 * That is the whole trick, and it is why the shapes are what they are:
 *
 *   reposo        icosahedron         12 vertices at radius 1
 *   pensando      spiked icosahedron  the same 12, radii oscillating out of phase
 *   respondiendo  octahedron          the same 12, collapsed 2-to-1 onto the axes
 *
 * The last one is the piece of luck. An icosahedron's twelve vertices sit at
 * (0, ±1, ±φ) and its rotations, which means they pair up perfectly: two of
 * them are nearest to +Z, two to −Z, two to +X, and so on — six directions,
 * two vertices each. Send each vertex to its axis and the icosahedron folds
 * into a clean octahedron. Six of the thirty edges collapse to nothing, the
 * other twenty-four land in coincident pairs on the octahedron's twelve. No
 * cross-fade, no second geometry, no popping: one shape genuinely becoming
 * another, because the second was hiding inside the first all along.
 *
 * Everything is drawn as lines — edges only, never a face. There is no fill
 * anywhere in this file, by design.
 *
 * WHY LineSegments2 AND NOT LineSegments
 *
 * Because WebGL will not draw a thick line. `linewidth` on LineBasicMaterial is
 * silently ignored on every desktop platform — the ANGLE and core-profile
 * drivers clamp it to 1px — so a wireframe built the obvious way can never be
 * anything but hairline. LineSegments2 sidesteps the driver entirely: each
 * segment is expanded into a camera-facing quad in the vertex shader, so
 * thickness is real geometry and behaves.
 *
 * The cost is that the material needs to know the canvas size (it converts
 * pixel widths into clip space itself), which is why #resize touches the
 * material and not just the camera. Miss that and the lines are the right
 * thickness only at whatever size the canvas happened to be at boot.
 *
 * With twelve vertices to move, positions are written on the CPU each frame.
 * A vertex shader would be the right answer at ten thousand; at twelve it is
 * ceremony, and this way the shapes stay readable as arithmetic.
 *
 * Usage:
 *
 *   const orb = new AssistantOrb(canvas, { color: 0xECBB12 });
 *   orb.setState('pensando');
 *   orb.destroy();
 *
 * TUNING. Every number below is in ORB_DEFAULTS, and orb.settings is a live
 * copy the render loop reads each frame — change a value and the orb changes
 * with it, no restart. /admin/orbe drives exactly that and prints the result
 * back as a block to paste over ORB_DEFAULTS once the numbers feel right.
 *
 * SETTING IT FROM A BLADE. The canvas configures its own orb, so a view can
 * say what it wants without any JavaScript:
 *
 *   <x-assistant-orb line-width="4" size="1.2" state="pensando" />
 *
 * That renders a canvas carrying data-orb-settings, which the constructor
 * reads and merges over the defaults — deeply, so a view can override one
 * number of one state and leave the rest alone. Markup wins over both the
 * defaults and anything passed in JavaScript: it is the most local statement
 * of intent, and it is the one someone editing a page can actually see.
 */

const PHI = (1 + Math.sqrt(5)) / 2;

/** The three states, in the order a question actually moves through them. */
export const ORB_STATES = ['reposo', 'pensando', 'respondiendo'];

/**
 * The arrangements the twelve vertices can sit in.
 *
 * Each one is a fold: every vertex is sent to its nearest seat, and vertices
 * sharing a seat drag their edge down to nothing. What makes a fold usable is
 * whether the edges that survive land exactly on the target's own edges — if
 * they do not, you get stray lines cutting across faces and the outline stops
 * reading. These four do; a cube, for instance, does not (twelve vertices onto
 * eight corners divides unevenly, and three extra lines cross its faces).
 *
 *   icosaedro   12 seats, 1 each   30 edges
 *   octaedro     6 seats, 2 each   12 edges,  6 collapse
 *   piramide     5 seats           12 lines: base, sides and base diagonals
 *   tetraedro    4 seats, 3 each    6 edges, 12 collapse
 */
export const ORB_SHAPES = ['icosaedro', 'octaedro', 'piramide', 'tetraedro'];

/**
 * Every tunable number in one place.
 *
 * Per state:
 *   shape    which arrangement the twelve vertices take
 *   spin     radians per second, per axis
 *   breath   whole-shape scale oscillation: [amplitude, hertz]
 *   spike    per-vertex radial wobble: [amplitude, hertz]
 *   opacity  line opacity
 */
export const ORB_DEFAULTS = {
    size: 1,
    /** Screen pixels, and honoured — see the LineSegments2 note above. */
    lineWidth: 2,
    transitionMs: 700,
    states: {
        // The pyramid only reads as a pyramid off-axis — head-on it projects
        // exactly like the octahedron — which is why resting is not still. The
        // slow deep breath is what keeps it alive while nobody is asking
        // anything.
        reposo: {
            shape: 'piramide',
            spin: [0.37, 0.57, 0.09],
            breath: [0.14, 0.08],
            spike: [0, 0],
            opacity: 1,
        },
        // Tighter and faster than resting, with the spikes carrying the
        // agitation instead of the breath.
        pensando: {
            shape: 'octaedro',
            spin: [0.34, 0.52, 0.21],
            breath: [0.015, 0.84],
            spike: [0.17, 1.5],
            opacity: 1,
        },
        // Complexity arrives with the answer: thirty edges on one axis, the
        // only state that turns in a single direction.
        respondiendo: {
            shape: 'icosaedro',
            spin: [0, 0.79, 0],
            breath: [0.045, 0.5],
            spike: [0, 0],
            opacity: 0.9,
        },
    },
};

export class AssistantOrb {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {{ color?: number|string, state?: string, settings?: object }} options
     */
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.color = options.color ?? 0xecbb12;

        // A copy, not the shared default: two orbs on one page must be able to
        // disagree, and the bench mutates this object in place.
        this.settings = merge(structuredClone(ORB_DEFAULTS), options.settings ?? {});
        this.settings = merge(this.settings, readSettings(canvas));

        this.state = canvas.dataset.orbInitial ?? options.state ?? 'reposo';
        this.previousState = this.state;
        this.transition = 1; // 0 = fully previous, 1 = fully current
        this.pulse = 0; // decaying kick on entering a state

        this.clock = new THREE.Clock();
        this.elapsed = 0;
        this.frame = null;

        // Motion is a preference, not a given. Under reduce, the orb still
        // changes shape between states — that is information — but it stops
        // spinning and breathing.
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.#buildGeometry();
        this.#buildScene();

        this.onResize = () => this.#resize();
        window.addEventListener('resize', this.onResize);
        this.#resize();

        this.#tick();
    }

    /**
     * The twelve vertices, the thirty edges, and where each vertex goes in
     * each shape.
     */
    #buildGeometry() {
        // Canonical icosahedron: three orthogonal golden rectangles.
        const raw = [];
        for (const s1 of [1, -1]) {
            for (const s2 of [1, -1]) {
                raw.push([0, s1, s2 * PHI], [s1, s2 * PHI, 0], [s2 * PHI, 0, s1]);
            }
        }

        const icosaedro = raw.map((v) => new THREE.Vector3(...v).normalize());

        // Edges by distance rather than by table: on a regular solid every
        // edge is the shortest distance there is, so the shape describes its
        // own connectivity and there is no hand-typed list to get wrong.
        let shortest = Infinity;
        for (let i = 0; i < icosaedro.length; i++) {
            for (let j = i + 1; j < icosaedro.length; j++) {
                shortest = Math.min(shortest, icosaedro[i].distanceTo(icosaedro[j]));
            }
        }

        this.edges = [];
        for (let i = 0; i < icosaedro.length; i++) {
            for (let j = i + 1; j < icosaedro.length; j++) {
                if (Math.abs(icosaedro[i].distanceTo(icosaedro[j]) - shortest) < 1e-6) {
                    this.edges.push([i, j]);
                }
            }
        }

        // Each vertex's octahedron seat: its dominant axis. Two vertices land
        // on each of the six, which is what folds the shape.
        const octaedro = icosaedro.map((v) => {
            const { x, y, z } = v;
            const ax = Math.abs(x);
            const ay = Math.abs(y);
            const az = Math.abs(z);

            if (ax >= ay && ax >= az) return new THREE.Vector3(Math.sign(x), 0, 0);
            if (ay >= az) return new THREE.Vector3(0, Math.sign(y), 0);

            return new THREE.Vector3(0, 0, Math.sign(z));
        });

        // A square pyramid is half an octahedron: pull the bottom apex up into
        // the plane of the square and it lands on the base's centre. The four
        // edges it used to own become the base's diagonals, so the shape draws
        // itself as base + sides + a cross — twelve lines, none of them stray.
        const piramide = octaedro.map((seat) =>
            seat.z < -0.5 ? new THREE.Vector3(0, 0, 0) : seat,
        );

        // The tetrahedron's four corners are alternate corners of a cube, and
        // the twelve vertices divide onto them exactly three apiece. Twelve of
        // the thirty edges collapse; the eighteen that remain land in threes on
        // the tetrahedron's six. The starkest the orb can get: six lines.
        const corners = [[1, 1, 1], [1, -1, -1], [-1, 1, -1], [-1, -1, 1]]
            .map((c) => new THREE.Vector3(...c).normalize());

        const tetraedro = icosaedro.map((v) =>
            corners.reduce((best, c) => (v.dot(c) > v.dot(best) ? c : best)),
        );

        this.shapes = { icosaedro, octaedro, piramide, tetraedro };

        // Golden-angle phases: twelve offsets that never fall into step, so the
        // spikes read as searching rather than as a pulsing heartbeat.
        this.phase = icosaedro.map((_, i) => i * 2.39996);

        this.scratchpad = icosaedro.map(() => new THREE.Vector3());
        this.positions = new Float32Array(this.edges.length * 2 * 3);
    }

    #buildScene() {
        this.renderer = new THREE.WebGLRenderer({
            canvas: this.canvas,
            alpha: true,
            antialias: true,
        });
        this.renderer.setClearColor(0x000000, 0);

        this.scene = new THREE.Scene();

        this.camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
        this.camera.position.set(0, 0, 4.2);

        // setPositions builds the instanced buffers. It is called once here;
        // the render loop writes into that buffer in place rather than calling
        // it again, which would allocate a fresh one sixty times a second.
        this.geometry = new LineSegmentsGeometry();
        this.geometry.setPositions(this.positions);

        this.instances = this.geometry.attributes.instanceStart.data;

        this.material = new LineMaterial({
            color: this.color,
            linewidth: this.settings.lineWidth,
            transparent: true,
            opacity: this.settings.states[this.state].opacity,
        });

        this.lines = new LineSegments2(this.geometry, this.material);

        // The vertices move every frame and the bounding volume does not follow
        // them, so a culling test would eventually decide the orb is off screen
        // and stop drawing it. There is exactly one object in this scene.
        this.lines.frustumCulled = false;

        this.scene.add(this.lines);
    }

    /**
     * Move to a new state. Re-entering the state it is already in is a no-op
     * rather than a restart, so a stream of "still thinking" updates does not
     * make the orb stutter.
     *
     * @param {'reposo'|'pensando'|'respondiendo'} next
     */
    setState(next) {
        if (! ORB_STATES.includes(next) || next === this.state) {
            return;
        }

        this.previousState = this.state;
        this.state = next;
        this.transition = 0;
        this.pulse = 1;
    }

    /** Where vertex `i` sits in `state` at time `t`, before any rotation. */
    #vertexFor(state, i, t) {
        const config = this.settings.states[state];
        const base = this.shapes[config.shape] ?? this.shapes.icosaedro;
        const [amplitude, hertz] = config.spike;

        if (amplitude === 0 || this.reducedMotion) {
            return base[i];
        }

        const wobble = 1 + amplitude * Math.sin(t * hertz * Math.PI * 2 + this.phase[i]);

        return this.scratchpad[i].copy(base[i]).multiplyScalar(wobble);
    }

    #tick = () => {
        this.frame = requestAnimationFrame(this.#tick);

        // Delta first, and elapsed accumulated by hand. Clock.getElapsedTime()
        // consumes the delta internally, so asking for both in that order
        // leaves getDelta() returning zero and freezes everything that moves.
        const delta = Math.min(this.clock.getDelta(), 0.05);
        this.elapsed += delta;
        const t = this.elapsed;

        const duration = Math.max(1, this.settings.transitionMs);

        if (this.transition < 1) {
            this.transition = Math.min(1, this.transition + (delta * 1000) / duration);
        }

        // easeInOutCubic: the shape leaves slowly, crosses fast, arrives slowly.
        const e = this.transition;
        const eased = e < 0.5 ? 4 * e * e * e : 1 - Math.pow(-2 * e + 2, 3) / 2;

        this.pulse *= Math.pow(0.001, delta); // ~decays over a second

        const from = this.settings.states[this.previousState];
        const to = this.settings.states[this.state];

        // Breath and pulse scale the whole shape, so they belong here rather
        // than in the per-vertex maths.
        const breathAmp = lerp(from.breath[0], to.breath[0], eased);
        const breathHz = lerp(from.breath[1], to.breath[1], eased);
        const breath = this.reducedMotion
            ? 1
            : 1 + breathAmp * Math.sin(t * breathHz * Math.PI * 2);

        const scale = this.settings.size * breath * (1 + this.pulse * 0.12);

        let cursor = 0;
        const a = new THREE.Vector3();
        const b = new THREE.Vector3();

        for (const [i, j] of this.edges) {
            a.copy(this.#vertexFor(this.previousState, i, t))
                .lerp(this.#vertexFor(this.state, i, t), eased)
                .multiplyScalar(scale);

            b.copy(this.#vertexFor(this.previousState, j, t))
                .lerp(this.#vertexFor(this.state, j, t), eased)
                .multiplyScalar(scale);

            this.positions[cursor++] = a.x;
            this.positions[cursor++] = a.y;
            this.positions[cursor++] = a.z;
            this.positions[cursor++] = b.x;
            this.positions[cursor++] = b.y;
            this.positions[cursor++] = b.z;
        }

        // The interleaved buffer is start.xyz + end.xyz per segment, which is
        // exactly the layout written above — so it copies straight across.
        this.instances.array.set(this.positions);
        this.instances.needsUpdate = true;

        if (! this.reducedMotion) {
            this.lines.rotation.x += lerp(from.spin[0], to.spin[0], eased) * delta;
            this.lines.rotation.y += lerp(from.spin[1], to.spin[1], eased) * delta;
            this.lines.rotation.z += lerp(from.spin[2], to.spin[2], eased) * delta;
        }

        this.material.opacity = lerp(from.opacity, to.opacity, eased);
        this.material.linewidth = this.settings.lineWidth;

        this.renderer.render(this.scene, this.camera);
    };

    #resize() {
        const { clientWidth: w, clientHeight: h } = this.canvas;

        if (w === 0 || h === 0) {
            return;
        }

        // Capped at 2: past that it is heat, not sharpness.
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();

        // LineMaterial converts a pixel width into clip space itself, so it
        // needs the canvas size in CSS pixels. Without this the thickness is
        // only correct at whatever size the canvas was when it booted.
        this.material?.resolution.set(w, h);
    }

    /** Line colour is the page's, not the orb's — see the themed dashboard. */
    setColor(color) {
        this.color = color;
        this.material.color.set(color);
    }

    /** Reset the orientation, so a spin setting can be judged from zero. */
    resetRotation() {
        this.lines.rotation.set(0, 0, 0);
    }

    destroy() {
        cancelAnimationFrame(this.frame);
        window.removeEventListener('resize', this.onResize);
        this.geometry.dispose();
        this.material.dispose();
        this.renderer.dispose();
    }
}

function lerp(a, b, t) {
    return a + (b - a) * t;
}

/**
 * Deep merge, so a view can override one number of one state without having to
 * restate the other seven. Arrays are replaced whole: [spin x, y, z] is one
 * value expressed as three, and merging it element-wise would let a view set
 * the X of a spin and silently inherit the Y of something else.
 */
function merge(base, patch) {
    for (const [key, value] of Object.entries(patch ?? {})) {
        base[key] = value && typeof value === 'object' && ! Array.isArray(value)
            ? merge(base[key] ?? {}, value)
            : value;
    }

    return base;
}

/** Whatever the Blade put on the canvas, or nothing if it put something bad. */
function readSettings(canvas) {
    if (! canvas.dataset.orbSettings) {
        return {};
    }

    try {
        return JSON.parse(canvas.dataset.orbSettings);
    } catch (error) {
        // A typo in a view should not take the page down with it, but it must
        // not pass silently either — the orb would just look wrong.
        console.warn('Orb: data-orb-settings is not valid JSON, ignoring it.', error);

        return {};
    }
}
