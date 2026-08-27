import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        cors: true,
        hmr: {
            host: 'localhost',
        },
        // Bind-mount Windows→WSL2 não propaga inotify, então o watcher do Vite pode não
        // ver arquivo alterado — o dev server segue servindo o módulo antigo mesmo
        // depois de salvar (aconteceu em 2026-08-10: a navbar parecia não ter mudado,
        // mas o arquivo dentro do container já estava certo; era cache do Vite).
        // usePolling é a mitigação padrão pra esse cenário, MAS não foi possível
        // confirmar que resolve aqui — depois de ligar isto, uma alteração ainda não foi
        // detectada em teste. Se a tela não refletir uma mudança de front, o que
        // resolve de fato é `docker compose restart vite`.
        watch: {
            usePolling: true,
            interval: 300,
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
