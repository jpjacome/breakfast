import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Neo-grotesque stand-in for the brand face. Swap for a
                // licensed Helvetica Now / ABC Diatype / Söhne when one is
                // acquired — only --font-sans in general.css needs to change.
                bunny('Inter', {
                    weights: [400, 500, 700, 900],
                }),
            ],
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
