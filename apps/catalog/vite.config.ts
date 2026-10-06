import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

// Vite w kontenerze `catalog-vite` zawsze słucha na 5173, ale na HOŚCIE ten port
// bywa inny (compose: "127.0.0.1:${VITE_PORT}:5173") — np. gdy 5173 zajmuje inny
// projekt (docs/RUNBOOK.md #022). Przeglądarka ładuje assety i łączy HMR z adresu
// zapisanego w `public/hot`, więc musi to być adres HOSTA, nie kontenera.
// Bez tego `public/hot` zawierał `http://0.0.0.0:5173` — i przeglądarka po cichu
// ładowała skrypty z CUDZEGO serwera Vite, który akurat siedział na 5173.
const vitePublicPort = process.env.VITE_PUBLIC_PORT;

export default defineConfig({
    server: vitePublicPort
        ? { hmr: { host: 'localhost', clientPort: Number(vitePublicPort) } }
        : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
