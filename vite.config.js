import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * Assets are built on the host and the compiled output in public/build is committed, so deploying
 * is a git pull and a migrate with no Node on the server.
 *
 * There is deliberately no font plugin here. IBM Plex is installed from npm and imported in
 * app.css, so the fonts are served from this origin: a control plane's content security policy
 * forbids third-party origins, and a font request is still a request that leaks who is looking at
 * what, and when.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
