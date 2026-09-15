import { onMounted, onUnmounted, ref } from 'vue';

/**
 * "Dá para instalar o PALMA neste aparelho?" — e o `prompt()` do Chrome, se ele existir.
 *
 * ⚠️ iOS NÃO DISPARA `beforeinstallprompt`. Lá o atalho nasce em Compartilhar →
 * Adicionar à Tela de Início, e o Chrome/Android é que mostra o botão nativo. Por
 * isso `podeInstalar` e `ehIos` são estados diferentes: um sem o outro faria o
 * card do Perfil ou oferecer um botão que não faz nada, ou esconder as instruções
 * exatamente de quem precisa delas.
 *
 * ⚠️ `instalado` lê `display-mode: standalone` (e o `navigator.standalone` do
 * iOS). Sem isso o card continuaria oferecendo "adicionar" para quem JÁ abriu
 * pelo ícone da home.
 *
 * ⚠️ O `beforeinstallprompt` DISPARA UMA VEZ, no carregamento da página, e o
 * Vite fatia o Perfil num chunk à parte. Se a captura vivesse só no card, o
 * evento já teria passado quando a pessoa abrisse o Perfil — botão morto, sem
 * erro. Por isso `capturarPromptDeInstalacao()` é chamado do `app.js`.
 */

let deferred = null;
let capturaLigada = false;
let acabouDeInstalar = false;
const ouvintes = new Set();

function avisar() {
    ouvintes.forEach((fn) => fn());
}

const aoAntesDeInstalar = (event) => {
    event.preventDefault();
    deferred = event;
    avisar();
};

const aoAppInstalado = () => {
    deferred = null;
    acabouDeInstalar = true;
    avisar();
};

/** Liga a captura cedo. Idempotente — o `app.js` chama no boot. */
export function capturarPromptDeInstalacao() {
    if (capturaLigada || typeof window === 'undefined') {
        return;
    }

    capturaLigada = true;
    window.addEventListener('beforeinstallprompt', aoAntesDeInstalar);
    window.addEventListener('appinstalled', aoAppInstalado);
}

export function usePwa() {
    capturarPromptDeInstalacao();

    const consulta = typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(display-mode: standalone)')
        : null;

    const instalado = ref(
        acabouDeInstalar
        || (consulta?.matches ?? false)
        || (typeof navigator !== 'undefined' && navigator.standalone === true),
    );
    const podeInstalar = ref(deferred !== null);
    const ehIos = ref(
        typeof navigator !== 'undefined' && (
            /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
        ),
    );

    const sincronizar = () => {
        podeInstalar.value = deferred !== null;
        instalado.value = acabouDeInstalar
            || (consulta?.matches ?? false)
            || navigator.standalone === true;
    };

    async function instalar() {
        if (! deferred) {
            return false;
        }

        deferred.prompt();
        const escolha = await deferred.userChoice;
        deferred = null;
        podeInstalar.value = false;

        return escolha.outcome === 'accepted';
    }

    onMounted(() => {
        sincronizar();
        ouvintes.add(sincronizar);
    });

    onUnmounted(() => {
        ouvintes.delete(sincronizar);
    });

    return { instalado, podeInstalar, ehIos, instalar };
}
