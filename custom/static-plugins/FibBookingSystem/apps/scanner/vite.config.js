import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The dev server proxies /api to the local Shopware stack so the app can be
// developed against `make up` without CORS configuration.
export default defineConfig({
    plugins: [vue()],
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: process.env.SCANNER_API_TARGET ?? 'http://booking.docker',
                changeOrigin: true,
            },
        },
    },
    build: {
        outDir: 'dist',
        sourcemap: false,
    },
});
