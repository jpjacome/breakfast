import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            /*
             * ⚠️ `npm run dev` MUST SERVE OVER TLS HERE, or it serves nothing.
             *
             * The site is https://breakfast.test (Herd), and without this the
             * dev server comes up on http://localhost:5173 — so every script
             * and stylesheet it injects is mixed active content and the browser
             * blocks all of it. The page then loads with no CSS and no JS,
             * which looks like the dev server being broken rather than the
             * browser refusing it.
             *
             * detectTls finds Herd's own certificate for this host
             * (~/.config/herd/config/valet/Certificates/breakfast.test.*), so
             * there is nothing to generate and nothing to trust by hand.
             *
             * ⚠️ DEV ONLY. `npm run build` never reads this, so it cannot
             * affect what ships — and `public/hot` is what makes Laravel prefer
             * the dev server at all. Delete that file and the built manifest
             * takes over again.
             */
            detectTls: 'breakfast.test',

            // orb-demo.js is its own entry on purpose: app.js is the public
            // site's GSAP bundle, and three.js has no business shipping to
            // someone reading the podcast page.
            // One stylesheet per blade, plus general.css which every blade
            // loads alongside it. A page never ships another page's CSS.
            input: [
                'resources/css/general.css',
                'resources/css/layout.css',
                'resources/css/admin.css',
                'resources/css/dashboard.css',
                'resources/css/process.css',
                'resources/css/files.css',
                'resources/css/admin-brand-egg.css',
                // The Egg assistant's panel — admin only, beside the rings.
                'resources/css/egg-assistant.css',
                // Component stylesheets, not pages': these render in BOTH
                // shells, so neither shell's file can own them.
                'resources/css/permissions.css',
                // Rendered in both shells, like permissions.css — see its header.
                'resources/css/checklist.css',
                // Chat attachments: the three transcripts live in both shells.
                'resources/css/attachments.css',
                'resources/css/brand-egg.css',
                // The client's read-only egg. A PAGE stylesheet beside the
                // component one above, the way admin-brand-egg.css is.
                'resources/css/portal-brand-egg.css',
                'resources/css/assistant.css',
                'resources/css/meetings.css',
                'resources/css/staff.css',
                'resources/css/account.css',
                'resources/css/auth.css',
                'resources/css/login.css',
                'resources/css/home-remake.css',
                'resources/css/nosotros.css',
                'resources/css/servicios.css',
                'resources/css/podcast.css',
                'resources/css/contacto.css',
                'resources/css/carta.css',
                'resources/css/brand-egg-map.css',

                'resources/js/app.js',
                'resources/js/assistant.js',
                'resources/js/client-draft.js',
                'resources/js/process-assistant.js',
                'resources/js/process.js',
                'resources/js/checklist.js',
                'resources/js/brand-egg.js',
                'resources/js/egg-assistant.js',
                'resources/js/brand-egg-split.js',
                'resources/js/copy-link.js',
                'resources/js/lightbox.js',
                'resources/js/orb-demo.js',
            ],
            refresh: true,
            fonts: [
                // Neo-grotesque stand-in for the brand face. Swap for a
                // licensed Helvetica Now / ABC Diatype / Söhne when one is
                // acquired — only --font-sans in general.css needs to change.
                bunny('Inter', {
                    weights: [400, 500, 700, 900],
                }),
                // Public marketing site only. These two are the faces the
                // Squarespace site actually ships: Libre Baskerville sets every
                // heading, Almarai every paragraph. The portal stays on Inter.
                bunny('Libre Baskerville', {
                    weights: [400, 700],
                }),
                bunny('Almarai', {
                    weights: [400, 700],
                }),
            ],
        }),
    ],
    /*
     * STABLE FILENAMES — no content hash.
     *
     * Deploys here are FTP uploads of changed files (see CLAUDE.md §3), and a
     * hashed name means every edit lands as a NEW file plus a rewritten
     * manifest.json, which had to be uploaded in that order or the site came
     * up unstyled. The output name is the address you upload to, so it has to
     * hold still: resources/css/admin.css is always
     * public/build/assets/admin.css, today and next month.
     *
     * The hash was doing one real job — busting the one-YEAR browser cache the
     * root .htaccess puts on text/css. That job moved to a ?v= query stamped
     * from each file's own mtime; see Vite::createAssetPathsUsing() in
     * AppServiceProvider.
     *
     * JS lands in assets/js/ and everything else directly in assets/, which is
     * cosmetic. The function is not: a CSS entry also produces a JS chunk (an
     * empty stub Vite then discards) and that stub RESERVES the entry's
     * basename. With one pattern for both, assistant.css took the name and
     * resources/js/assistant.js was renamed assistant2.js — a number assigned
     * by input order, so reordering the array above would silently move it to
     * the other file. Sending the stubs to a path of their own keeps the name
     * they reserve out of the way. Nothing is written there; only the name is
     * spent.
     *
     * A new entry sharing a basename with an existing one of the SAME kind
     * would still collide silently. Check the build output for a "2" after
     * adding one.
     */
    build: {
        rollupOptions: {
            output: {
                entryFileNames: (chunk) => /\.css$/.test(chunk.facadeModuleId || '')
                    ? 'assets/css-entry-stub/[name].js'
                    : 'assets/js/[name].js',
                chunkFileNames: 'assets/js/[name].js',
                assetFileNames: 'assets/[name][extname]',
            },
        },
    },
    server: {
        watch: {
            // public/** is served as-is and never needs HMR. It is ignored
            // because this project sits inside OneDrive: OneDrive locks files
            // while it syncs them, and a watcher landing on a locked file
            // throws EBUSY, which kills the whole dev server. Dropping an image
            // into public/img was enough to take it down.
            ignored: ['**/storage/framework/views/**', '**/public/**'],
        },
    },
});
