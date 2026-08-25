import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    resolve: {
        // `@goldnead/flow-canvas` is installed from a Composer path repository
        // and linked by npm, so its files sit outside this project. Without
        // this, `vue`, `@vue-flow/*` and `@statamic/cms` cannot be resolved
        // from there — and they must resolve *here*, so the page ends up with
        // one Vue and one flow library.
        preserveSymlinks: true,

        // One copy of the flow library, and one Vue. The shared package carries
        // these as devDependencies so its own Node tests can resolve them, and
        // without deduping, a build can end up with two: the store one instance
        // registers is then invisible to the other, and the canvas dies at setup
        // with "Cannot destructure property 'getState' of undefined".
        dedupe: ['vue', '@vue-flow/core', '@vue-flow/background', '@vue-flow/controls', '@vue-flow/minimap'],
    },

    plugins: [
        statamic(),
        tailwindcss(),
        laravel({
            hotFile: 'dist/hot',
            publicDirectory: 'dist',
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
        }),
    ],
});
