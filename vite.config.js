import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Bundle aparte para el panel: no comparte nada con el sitio
                // publico, que arrastra Tailwind.
                'resources/css/admin.css',
                // El panel solo necesita JavaScript para la cola de
                // verificacion: ampliar un comprobante y avisar de una
                // diferencia de importe. Todo lo demas son formularios.
                'resources/js/admin.js',
                // Sitio publico de donaciones: formulario, resultado y dashboard.
                'resources/js/publico.js',
            ],
            refresh: true,
            fonts: [
                bunny('Manrope', { weights: [400, 500, 600, 700, 800] }),
                bunny('Caveat', { weights: [500, 600, 700] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
