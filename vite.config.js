import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// HMR host só é usado no `npm run dev` (produção usa os assets já buildados).
// Para acessar o dev server de outro aparelho na rede, defina VITE_HMR_HOST=<ip>.
const hmrHost = process.env.VITE_HMR_HOST;

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        ...(hmrHost ? { hmr: { host: hmrHost } } : {}),
    },
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
    ],
});
