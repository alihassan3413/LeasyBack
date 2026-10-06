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
    plugins: [tailwindcss(), vue(), Icons({ compiler: 'vue3', autoInstall: true })],
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
                'license-plate': path.resolve(import.meta.dirname, 'license-plate.html'),
                relocation: path.resolve(import.meta.dirname, 'relocation.html'),
                'additional-damage': path.resolve(import.meta.dirname, 'additional-damage.html'),
                'document-actions': path.resolve(import.meta.dirname, 'document-actions.html'),
            },
        },
    },
});
