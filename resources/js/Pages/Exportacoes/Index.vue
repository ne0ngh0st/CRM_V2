<script setup>
/**
 * Meus downloads — as planilhas que a pessoa pediu.
 *
 * ⚠️ POR QUE ESTA TELA EXISTE: o arquivo tinha UM ponteiro só, a notificação do sino, que
 * some quando é lida. Depois disso a planilha ficava mais 7 dias no disco sem caminho
 * nenhum até ela. As outras oito exportações nem ponteiro tinham — desciam para a pasta
 * de downloads do navegador e acabou.
 *
 * ⚠️ Lista SÓ as próprias exportações, para todos os perfis, inclusive admin. Um .xlsx da
 * Carteira contém a base de clientes inteira de alguém; quem precisa dos dados gera a
 * própria planilha. A regra vale aqui e na rota de download, que confere de novo.
 */
import { computed, onBeforeUnmount, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    exportacoes: { type: Object, required: true },
    diasValidade: { type: Number, default: 7 },
    emAndamento: { type: Boolean, default: false },
});

/*
 * ⚠️ A tela se recarrega sozinha ENQUANTO houver planilha em preparo, e só nesse caso —
 * mesmo desenho de /atualizacoes. Quem chega aqui vindo do aviso "estamos preparando"
 * veria um "processando" congelado e clicaria em exportar de novo.
 *
 * `only:` limita o payload ao que muda; a paginação inteira não precisa voltar.
 */
const POLL_MS = 5000;
let timer = null;

function pararPoll() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function ajustarPoll(ativo) {
    pararPoll();
    if (!ativo) return;

    timer = setInterval(() => {
        router.reload({ only: ['exportacoes', 'emAndamento'] });
    }, POLL_MS);
}

watch(() => props.emAndamento, ajustarPoll, { immediate: true });
onBeforeUnmount(pararPoll);

const linhasDaPagina = computed(() => props.exportacoes.data ?? []);

const disponiveis = computed(() => linhasDaPagina.value.filter((e) => e.disponivel).length);

/** Estado visual de cada linha: uma pergunta, uma resposta. */
function estado(e) {
    // ⚠️ Antes de "Preparando": um job cujo worker morreu fica processando para sempre, e
    // dizer "Preparando" a um arquivo abandonado faz a pessoa esperar indefinidamente.
    if (e.travou) return { tone: 'danger', texto: 'Interrompida' };
    if (e.status === 'processando') return { tone: 'warn', texto: 'Preparando' };
    if (e.status === 'erro') return { tone: 'danger', texto: 'Falhou' };
    // ⚠️ `pronto` e vencida continua sendo `pronto` no banco — quem sabe a diferença é o
    // servidor, que já mandou `disponivel`/`expirou` resolvidos.
    if (e.expirou || !e.disponivel) return { tone: 'neutral', texto: 'Expirada' };
    return { tone: 'ok', texto: 'Disponível' };
}

function tamanho(bytes) {
    if (!bytes) return '—';
    const mb = bytes / 1048576;
    return mb >= 1 ? `${mb.toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function numero(n) {
    return n == null ? '—' : Number(n).toLocaleString('pt-BR');
}
</script>

<template>
    <Head title="Meus downloads" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Meus downloads">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    As planilhas que você gerou ficam aqui por {{ diasValidade }} dias, com os filtros que estavam
                    valendo na hora. Depois disso o arquivo é apagado e o registro continua na lista.
                </template>
                <template #meta>
                    <StatusPill v-if="emAndamento" tone="warn" surface="dark">Gerando agora</StatusPill>
                    <StatusPill v-if="disponiveis > 0" tone="ok" surface="dark">
                        {{ disponiveis }} disponíve{{ disponiveis === 1 ? 'l' : 'is' }}
                    </StatusPill>
                </template>
            </PageHero>

            <DarkCard title="Planilhas geradas" subtitle="Da mais recente para a mais antiga">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <rect x="4" y="3" width="16" height="18" rx="2" />
                        <path d="M8 8h8M8 12h8M8 16h5" stroke-linecap="round" />
                    </svg>
                </template>

                <div v-if="linhasDaPagina.length === 0" class="px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-600">Você ainda não gerou nenhuma planilha.</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-gray-400">
                        O botão de Excel fica no topo das listagens — Carteira, Leads, Pedidos, Orçamentos,
                        Tabela de Preços, Equipe, Metas e Cadastros. O que você gerar aparece aqui.
                    </p>
                </div>

                <template v-else>
                    <div class="tbl-wrap">
                        <table class="tbl min-w-[900px]">
                            <thead>
                                <tr class="tbl-head-row">
                                    <th class="tbl-th">Planilha</th>
                                    <th class="tbl-th">Filtros do pedido</th>
                                    <th class="tbl-th">Linhas</th>
                                    <th class="tbl-th">Tamanho</th>
                                    <th class="tbl-th">Gerada em</th>
                                    <th class="tbl-th">Situação</th>
                                    <th class="tbl-th">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="tbl-body">
                                <tr v-for="e in linhasDaPagina" :key="e.id" class="tbl-row">
                                    <td class="tbl-td">
                                        <span class="tbl-main max-w-[220px]">{{ e.recurso }}</span>
                                    </td>

                                    <td class="tbl-td">
                                        <!-- Responde "por que este Excel tem 300 linhas e não 90 mil?" — a
                                             pergunta que faz alguém desconfiar do arquivo e regerá-lo à toa. -->
                                        <div v-if="e.filtros.length" class="flex flex-wrap justify-center gap-1">
                                            <span
                                                v-for="(f, i) in e.filtros"
                                                :key="i"
                                                class="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[0.62rem] leading-4 text-gray-500"
                                            >
                                                <span class="font-medium text-gray-600">{{ f.rotulo }}:</span>{{ f.valor }}
                                            </span>
                                        </div>
                                        <span v-else class="tbl-sub">Base completa</span>
                                    </td>

                                    <td class="tbl-td">{{ numero(e.linhas) }}</td>
                                    <td class="tbl-td">{{ tamanho(e.bytes) }}</td>

                                    <td class="tbl-td">
                                        <span class="tbl-main">{{ e.criadoEm }}</span>
                                        <span v-if="e.disponivel" class="tbl-sub">expira {{ e.expiraEm }}</span>
                                    </td>

                                    <td class="tbl-td">
                                        <StatusPill :tone="estado(e).tone" size="sm">{{ estado(e).texto }}</StatusPill>
                                        <span v-if="e.travou" class="tbl-sub">gere de novo</span>
                                        <span v-else-if="e.status === 'erro' && e.erro" class="tbl-sub max-w-[200px] truncate" :title="e.erro">
                                            {{ e.erro }}
                                        </span>
                                    </td>

                                    <td class="tbl-td">
                                        <div class="tbl-acoes">
                                            <!-- ⚠️ `<a>` e não `<Link>`: baixar é navegação do navegador, não
                                                 visita do Inertia — o XHR receberia o binário e quebraria. -->
                                            <a
                                                v-if="e.disponivel"
                                                :href="e.url"
                                                class="tbl-acao tbl-acao-verde"
                                                title="Baixar planilha"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                    <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                            </a>
                                            <button
                                                v-else
                                                type="button"
                                                class="tbl-acao tbl-acao-neutro"
                                                disabled
                                                :title="e.status === 'processando' ? 'Ainda sendo gerada' : 'Arquivo não está mais disponível'"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                    <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pt-3">
                        <Pagination :meta="exportacoes" :only="['exportacoes', 'emAndamento']" />
                    </div>
                </template>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
