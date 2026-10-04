import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const port = Number(loadEnv(mode, process.cwd(), '').VITE_PORT || 5173);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/css/site.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            hmr: { host: 'localhost' },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
