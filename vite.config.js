import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        // Remove custom manifest name - let Vite use default
        outDir: 'public/build',
        emptyOutDir: true,
        rollupOptions: {
            output: {
                manualChunks: undefined,
            },
        },
    },
    // Only include server config for development
    ...(process.env.NODE_ENV === 'development' && {
        server: {
            host: '0.0.0.0',
            port: 5217,
            hmr: {
                host: 'localhost',
                clientPort: 5217
            }
        }
    })
});
