import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import Icons from 'unplugin-icons/vite';
import path from 'node:path';
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
    resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../../resources/js') } },
    build: {
        outDir: path.resolve(import.meta.dirname, 'dist'),
        emptyOutDir: true,
        rollupOptions: {
            input: {
                main: path.resolve(import.meta.dirname, 'index.html'),
                tasks: path.resolve(import.meta.dirname, 'tasks.html'),
            },
        },
    },
});
