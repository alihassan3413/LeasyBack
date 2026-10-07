import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import Icons from 'unplugin-icons/vite';
import { defineConfig } from 'vite';

/**
 * Standalone build of the layout fixture page.
 *
 * Deliberately separate from the application's own `vite.config.ts`: the
 * fixture wants the real Tailwind pipeline and the `@` alias, but none of
 * Laravel's manifest handling, and nothing here should end up in a production
 * bundle.
 */
export default defineConfig({
    root: path.resolve(import.meta.dirname),
    plugins: [
        tailwindcss(),
        // As in the app's vite.config.ts: public-folder URLs such as /path-green.svg stay URLs.
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        Icons({ compiler: 'vue3', autoInstall: true }),
    ],
    // A placeholder so the Places client is active; the specs intercept every
    // request to places.googleapis.com, nothing ever reaches Google.
    define: { 'import.meta.env.VITE_GOOGLE_PLACES_API_KEY': JSON.stringify('fixture-places-key') },
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, '../../../resources/js'),
            // Fixture only: the MFA pages import useForm/router/Head, which
            // need a running Inertia app. The application build is untouched.
            '@inertiajs/vue3': path.resolve(import.meta.dirname, 'inertia-stub.ts'),
        },
    },
    build: {
        outDir: path.resolve(import.meta.dirname, 'dist'),
        emptyOutDir: true,
        rollupOptions: {
            input: {
                main: path.resolve(import.meta.dirname, 'index.html'),
                tasks: path.resolve(import.meta.dirname, 'tasks.html'),
                gallery: path.resolve(import.meta.dirname, 'gallery.html'),
                picker: path.resolve(import.meta.dirname, 'picker.html'),
                positions: path.resolve(import.meta.dirname, 'positions.html'),
                extraction: path.resolve(import.meta.dirname, 'extraction.html'),
                mfa: path.resolve(import.meta.dirname, 'mfa.html'),
                relocation: path.resolve(import.meta.dirname, 'relocation.html'),
                'additional-damage': path.resolve(import.meta.dirname, 'additional-damage.html'),
                'document-actions': path.resolve(import.meta.dirname, 'document-actions.html'),
                'company-address': path.resolve(import.meta.dirname, 'company-address.html'),
                'b2b-registration': path.resolve(import.meta.dirname, 'b2b-registration.html'),
            },
        },
    },
});
