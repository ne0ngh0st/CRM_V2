<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

/*
 * Botão "Gerar Excel" das nove listagens do sistema.
 *
 * ⚠️ O BOTÃO NÃO SABE — E NÃO DEVE SABER — se a planilha vai sair na hora ou pela fila.
 * Quem decide é o volume, medido no servidor: a mesma Carteira leva 95 s para um admin
 * com 92 mil clientes e menos de um segundo para um vendedor com 283. Até 2026-09-09 isso
 * era uma prop fixa por página (`assincrono`), o que só podia acertar para um dos dois.
 *
 * Por isso o clique é SEMPRE a mesma requisição Inertia, e a reação vem do flash
 * `exportacao` que o servidor devolve: `pronta` dispara o download, `enfileirada` abre o
 * aviso do sino, `erro` avisa em vez de deixar o clique sem resposta.
 *
 * ⚠️ `window.location.href` para baixar, e não fetch/blob: o download é uma navegação do
 * próprio navegador, com a barra de progresso e a pasta de destino dele. Blob obrigaria a
 * carregar o .xlsx inteiro na memória da aba antes de salvar.
 */
const props = defineProps({
    rota: { type: String, required: true },
    filtros: { type: Object, default: () => ({}) },
    temFiltrosAtivos: { type: Boolean, default: false },
});

const page = usePage();

const mostrarAviso = ref(false);
const mostrarPreparando = ref(false);
const mostrarErro = ref(false);
const enviando = ref(false);
const resultado = ref(null);

const queryParams = computed(() =>
    Object.fromEntries(
        Object.entries(props.filtros).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    ),
);

function clicar() {
    if (props.temFiltrosAtivos) {
        mostrarAviso.value = true;
        return;
    }
    exportar();
}

function exportar() {
    mostrarAviso.value = false;
    enviando.value = true;

    router.post(route(props.rota), queryParams.value, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const dados = page.props.flash?.exportacao;
            if (!dados) return;

            resultado.value = dados;

            if (dados.estado === 'pronta' && dados.url) {
                window.location.href = dados.url;
                return;
            }

            if (dados.estado === 'erro') {
                mostrarErro.value = true;
                return;
            }

            mostrarPreparando.value = true;
        },
        onFinish: () => {
            enviando.value = false;
        },
    });
}

const linhas = computed(() =>
    resultado.value?.linhas != null ? Number(resultado.value.linhas).toLocaleString('pt-BR') : null,
);
</script>

<template>
    <button
        type="button"
        title="Gerar Excel"
        :disabled="enviando"
        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-600 text-gray-300 transition hover:border-emerald-400 hover:bg-emerald-400/10 hover:text-emerald-300 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-gray-600 disabled:hover:bg-transparent disabled:hover:text-gray-300"
        @click="clicar"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
            <path
                d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </button>

    <Modal :show="mostrarAviso" max-width="md" @close="mostrarAviso = false">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Exportar com filtros ativos</h2>
            <p class="mt-2 text-sm text-gray-500">
                Você tem filtros ativos na tela — o Excel será gerado só com os dados filtrados, não com a base
                completa. Deseja continuar?
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="mostrarAviso = false">Cancelar</SecondaryButton>
                <PrimaryButton type="button" @click="exportar">Exportar</PrimaryButton>
            </div>
        </div>
    </Modal>

    <!-- Sem este aviso, o clique que cai na fila não produziria retorno visível nenhum e o
         usuário clicaria de novo achando que falhou. -->
    <Modal :show="mostrarPreparando" max-width="md" @close="mostrarPreparando = false">
        <div class="p-6">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Preparando sua planilha</h2>
                    <p class="mt-2 text-sm text-gray-500">
                        <template v-if="linhas">São <strong>{{ linhas }}</strong> linhas — </template>
                        <template v-else>O volume é grande — </template>
                        gerar leva alguns minutos. Você pode continuar usando o sistema; avisaremos no sino
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="inline h-4 w-4 align-text-bottom">
                            <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        assim que estiver pronta.
                    </p>
                    <p class="mt-2 text-xs text-gray-400">
                        Ela também fica guardada em
                        <Link :href="route('exportacoes.index')" class="font-medium text-teal underline">Meus downloads</Link>,
                        onde dá para baixar de novo enquanto não expirar.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <PrimaryButton type="button" @click="mostrarPreparando = false">Entendi</PrimaryButton>
            </div>
        </div>
    </Modal>

    <!-- Falhar em silêncio seria pior que falhar: sem isto o usuário fica esperando um
         arquivo que nunca vem. -->
    <Modal :show="mostrarErro" max-width="md" @close="mostrarErro = false">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Não foi possível gerar a planilha</h2>
            <p class="mt-2 text-sm text-gray-500">
                Tente de novo. Se continuar falhando, aplique um filtro para reduzir o volume — o registro da
                tentativa fica em
                <Link :href="route('exportacoes.index')" class="font-medium text-teal underline">Meus downloads</Link>.
            </p>
            <div class="mt-6 flex justify-end">
                <PrimaryButton type="button" @click="mostrarErro = false">Entendi</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
