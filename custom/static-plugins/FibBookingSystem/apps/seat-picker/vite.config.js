import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Builds ONE self-contained IIFE that registers <fib-seat-picker>.
// Output lands in the plugin's public dir and is served via
// /bundles/fibbookingsystem/seat-picker/fib-seat-picker.js — dynamically
// imported by the calendar widget only when a seatmap resource is shown.
export default defineConfig({
    plugins: [
        vue({
            features: {
                customElement: true,
            },
        }),
    ],
    define: {
        // Vite lib builds keep process.env.NODE_ENV for downstream bundlers —
        // this IIFE runs directly in the browser, so inline it.
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        lib: {
            entry: 'src/main.js',
            formats: ['iife'],
            name: 'FibSeatPicker',
            fileName: () => 'fib-seat-picker.js',
        },
        outDir: '../../src/Resources/public/seat-picker',
        emptyOutDir: true,
    },
    test: {
        environment: 'node',
    },
});
