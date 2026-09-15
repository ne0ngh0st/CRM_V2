import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { capturarPromptDeInstalacao } from './composables/usePwa';

// Antes do Inertia montar: o `beforeinstallprompt` dispara uma vez e o Perfil é chunk à parte.
capturarPromptDeInstalacao();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

/*
 * PWA: o SW mora em `/sw.js` (escopo `/`), não no bundle do Vite — o Chrome exige isso
 * para "Adicionar à tela inicial". Registrar no `load` para não competir com a primeira
 * pintura. Falha é silenciosa de propósito: o CRM no browser continua igual.
 *
 * ⚠️ Também registra em DEV. O handler só intercepta `/build/assets/` (hash do Vite em
 * produção); no `npm run dev` esses arquivos não existem neste origin (vêm do :5173),
 * então o SW não luta com o HMR. Desligar só em DEV faria o atalho ser impossível de
 * conferir no localhost, que é o único ambiente "seguro" sem HTTPS.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
