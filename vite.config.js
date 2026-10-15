import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// HMR host só é usado no `npm run dev` (produção usa os assets já buildados).
// Para acessar o dev server de outro aparelho na rede, defina VITE_HMR_HOST=<ip>.
//
// Sem a variável, o padrão é `localhost`, e não o 0.0.0.0 do `host`: é dele que
// o plugin do Laravel tira o endereço dos scripts (public/hot), e o Chrome
// bloqueia http://0.0.0.0 — a página abre em branco.
const hmrHost = process.env.VITE_HMR_HOST ?? 'localhost';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: { host: hmrHost },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
    ],
});
