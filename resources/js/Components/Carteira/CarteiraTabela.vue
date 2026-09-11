<script setup>
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import SortableTh from '@/Components/Tabela/SortableTh.vue';
import BotoesContato from '@/Components/Contato/BotoesContato.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira.js';
import { ROTULOS_CANAL_CURTO } from '@/constants/contatos.js';

const props = defineProps({
    clientes: { type: Array, required: true },
    ordenar: { type: String, default: '' },
    // Uma linha por cliente, com as filiais na linha expansível.
    agrupado: { type: Boolean, default: false },
    podeVerDetalhes: { type: Boolean, default: false },
    podeLigar: { type: Boolean, default: false },
    podeAgendar: { type: Boolean, default: false },
    podeOrcamento: { type: Boolean, default: false },
    podeObservar: { type: Boolean, default: false },
});

const expandido = ref(null);
const carregando = ref(null);
const erro = ref(null);

/*
 * Filiais já buscadas, por cod_cliente. Guardar evita refazer a consulta quando a
 * pessoa fecha e reabre a mesma linha — comportamento comum em quem está comparando
 * dois clientes. O cache morre junto com a visita, então filtrar ou paginar traz dado
 * fresco sem esforço nenhum.
 */
const filiaisPorCliente = ref({});

const colunas = computed(() => (props.agrupado ? 10 : 9));

/*
 * Só cliente com mais de uma loja expande — 87,7% da base tem uma só, e para esses a
 * linha fica idêntica à do modo plano, sem seta e sem alvo de clique.
 */
function expansivel(cliente) {
    return props.agrupado && (cliente.lojas ?? 1) > 1;
}

async function alternar(cliente) {
    if (! expansivel(cliente)) return;

    const cod = cliente.codCliente;

    if (expandido.value === cod) {
        expandido.value = null;

        return;
    }

    expandido.value = cod;
    erro.value = null;

    if (filiaisPorCliente.value[cod]) return;

    carregando.value = cod;

    try {
        const resposta = await fetch(route('carteira.filiais', cod), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! resposta.ok) throw new Error(`HTTP ${resposta.status}`);

        filiaisPorCliente.value[cod] = await resposta.json();
    } catch {
        erro.value = cod;
    } finally {
        carregando.value = null;
    }
}

const emit = defineEmits(['motivo-inatividade', 'observacao', 'agendar-ligacao', 'ordenar']);

/*
 * Registra o contato no canal escolhido. `preserveState` mantém os filtros e a
 * página atual; o POST sai ANTES de o browser abrir o discador/WhatsApp/e-mail,
 * senão a navegação cancelaria a requisição e o contato não entraria na métrica.
 */
function registrarContato(cliente, tipo) {
    router.post(route('carteira.ligacao', cliente.id), { tipo }, { preserveScroll: true, preserveState: true });
}

function criarOrcamento(cliente) {
    router.get(route('orcamentos.novo'), {
        // ⚠️ O id é o que amarra o orçamento ao cliente do TOTVS. Sem ele o documento
        // nasce só com nome e CNPJ em texto e NÃO consegue virar pedido no Portal, cujo
        // de-para é por cod_cliente + loja.
        cliente_id: cliente.id,
        cliente_nome: cliente.razaoSocial,
        cliente_cnpj: cliente.cnpj ?? '',
        cliente_contato: cliente.telefone ?? '',
    });
}
</script>

<template>
    <div class="tbl-wrap">
        <!-- 1200px, não 1000: entraram a coluna "Último contato" e mais dois botões de
             ação. Com 1000 a coluna Ações espremia e os 7 botões empilhavam um por
             linha, triplicando a altura da linha em tela média. -->
        <table class="tbl min-w-[1200px]">
            <thead>
                <tr class="tbl-head-row">
                    <!-- Coluna do chevron, como em PedidosTabela. Só existe agrupado. -->
                    <th v-if="agrupado" class="tbl-th w-8"></th>
                    <SortableTh campo="nome" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Cliente</SortableTh>
                    <th class="tbl-th">Grupo</th>
                    <SortableTh campo="vendedor" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Vendedor</SortableTh>
                    <SortableTh campo="estado" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Estado</SortableTh>
                    <th class="tbl-th">Segmento</th>
                    <SortableTh campo="status" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Status</SortableTh>
                    <SortableTh campo="ultima_compra" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Última Compra</SortableTh>
                    <SortableTh campo="ultimo_contato" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Último contato</SortableTh>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="cliente in clientes" :key="cliente.id">
                <tr class="tbl-row" :class="expansivel(cliente) ? 'cursor-pointer' : ''" @click="alternar(cliente)">
                    <td v-if="agrupado" class="tbl-td text-gray-400">
                        <svg
                            v-if="expansivel(cliente)"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            class="mx-auto h-3.5 w-3.5 transition-transform"
                            :class="expandido === cliente.codCliente ? 'rotate-90' : ''"
                        >
                            <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-main max-w-[220px]" :title="cliente.razaoSocial">{{ cliente.razaoSocial }}</span>
                        <span class="tbl-sub">{{ cliente.cnpj ?? '—' }}</span>
                        <!-- Filial e entrega contadas separadamente: dizer que a AUTOPASS
                             tem 220 filiais seria falso — são 2 filiais e 218 pontos de
                             entrega (confirmado com o TOTVS). -->
                        <span v-if="expansivel(cliente)" class="tbl-sub text-cyan-dark">
                            {{ cliente.lojas - cliente.entregas }} {{ cliente.lojas - cliente.entregas === 1 ? 'filial' : 'filiais' }}<template v-if="cliente.entregas"> · {{ cliente.entregas }} entrega{{ cliente.entregas === 1 ? '' : 's' }}</template>
                        </span>
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[180px]" :title="cliente.grupo ?? ''">{{ cliente.grupo ?? '—' }}</span>
                    </td>
                    <td class="tbl-td">{{ cliente.vendedorNome }}</td>
                    <td class="tbl-td">{{ cliente.estado ?? '—' }}</td>
                    <td class="tbl-td">{{ cliente.segmento ?? '—' }}</td>
                    <td class="tbl-td">
                        <button
                            v-if="cliente.status === 'inativo'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border-0 bg-transparent p-0 transition hover:ring-2 hover:ring-red-300"
                            :title="cliente.motivoInatividade ? `Motivo: ${cliente.motivoInatividade.motivo}` : 'Motivo de inatividade pendente — clique pra registrar'"
                            @click.stop="emit('motivo-inatividade', cliente)"
                        >
                            <StatusPill tone="danger" size="sm">
                                {{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}
                                <svg v-if="!cliente.motivoInatividade" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5">
                                    <path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5">
                                    <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </StatusPill>
                        </button>
                        <StatusPill v-else :tone="TONS_STATUS_CARTEIRA[cliente.status]" size="sm">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
                    </td>
                    <td class="tbl-td">{{ cliente.dataUltimaCompra ?? 'Nunca' }}</td>
                    <td class="tbl-td">
                        <template v-if="cliente.ultimoContato">
                            <span class="tbl-main">{{ cliente.ultimoContato.data }}</span>
                            <span class="tbl-sub">{{ ROTULOS_CANAL_CURTO[cliente.ultimoContato.canal] ?? cliente.ultimoContato.canal }}</span>
                        </template>
                        <span v-else class="text-gray-400">Nunca</span>
                    </td>
                    <td class="tbl-td">
                        <!--
                          🚨 CLIENTE COM MAIS DE UMA LOJA NÃO TEM AÇÃO NA LINHA — ela fica
                          em cada filial, dentro da expansão.

                          O motivo não é estético: toda ação daqui é sobre uma filial
                          concreta. O telefone e o e-mail são da filial, `observacoes` e
                          `ligacoes` gravam `cliente_id` (que é a filial), e o orçamento
                          carrega o par `cod_cliente + loja` que o Portal Autopel usa como
                          de-para. Deixar os botões na linha agrupada faria todos eles
                          apontarem para a ÂNCORA: o vendedor que quisesse orçar a loja
                          0004 criaria o documento na 0002 sem nada indicar o engano, e o
                          erro só apareceria como pedido errado lá na frente.

                          Para cliente de uma loja só — 87,7% da base — a linha É a filial,
                          não há ambiguidade e os botões continuam aqui, como sempre.
                        -->
                        <button
                            v-if="expansivel(cliente)"
                            type="button"
                            class="mx-auto inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-[0.65rem] font-medium text-gray-500 transition hover:bg-gray-100"
                            :title="`Este cliente tem ${cliente.lojas} lojas — escolha em qual agir`"
                            @click.stop="alternar(cliente)"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 transition-transform" :class="expandido === cliente.codCliente ? 'rotate-90' : ''">
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Escolher filial
                        </button>

                        <!-- .stop: a linha inteira expande, mas os botões têm ação própria.
                             Sem isto, ligar para um cliente também abriria as filiais dele. -->
                        <div v-else class="tbl-acoes" @click.stop>
                            <Link
                                v-if="podeVerDetalhes"
                                :href="route('carteira.detalhes', cliente.id)"
                                title="Ver detalhes"
                                class="tbl-acao tbl-acao-neutro"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </Link>
                            <BotoesContato
                                v-if="podeLigar"
                                :telefone="cliente.telefone"
                                :email="cliente.email"
                                @contato="registrarContato(cliente, $event)"
                            />
                            <button
                                v-if="podeAgendar"
                                type="button"
                                title="Agendar ligação"
                                class="tbl-acao tbl-acao-cyan"
                                @click="emit('agendar-ligacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="4.5" width="18" height="16" rx="1.5" />
                                    <path d="M3 9h18M8 3v3M16 3v3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="m9 15 2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeOrcamento"
                                type="button"
                                title="Criar orçamento"
                                class="tbl-acao tbl-acao-navy"
                                @click="criarOrcamento(cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M7 3h7l4 4v14H7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="M14 3v4h4M9.5 13h5M9.5 16.5h5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeObservar"
                                type="button"
                                title="Observações"
                                class="tbl-acao tbl-acao-amber"
                                @click="emit('observacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <line x1="8" y1="9" x2="16" y2="9" stroke-linecap="round" />
                                    <line x1="8" y1="12.5" x2="13" y2="12.5" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>

                <tr v-if="agrupado && expandido === cliente.codCliente" class="bg-gray-50">
                    <td :colspan="colunas" class="p-4">
                        <p v-if="carregando === cliente.codCliente" class="text-xs text-gray-500">Carregando filiais…</p>

                        <p v-else-if="erro === cliente.codCliente" class="text-xs text-red-600">
                            Não foi possível carregar as filiais deste cliente.
                        </p>

                        <template v-else-if="filiaisPorCliente[cliente.codCliente]">
                            <table class="tbl-itens">
                                <thead>
                                    <tr class="tbl-itens-head-row">
                                        <th class="tbl-itens-th">Loja</th>
                                        <th class="tbl-itens-th">Razão social</th>
                                        <th class="tbl-itens-th">CNPJ</th>
                                        <th class="tbl-itens-th">UF</th>
                                        <th class="tbl-itens-th">Status</th>
                                        <th class="tbl-itens-th">Última compra</th>
                                        <th class="tbl-itens-th">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="tbl-body">
                                    <tr v-for="filial in filiaisPorCliente[cliente.codCliente].filiais" :key="filial.id" class="tbl-itens-row">
                                        <td class="tbl-itens-td">
                                            <span class="font-medium">{{ filial.loja }}</span>
                                            <!-- Marcado, não escondido: o endereço de entrega faz parte
                                                 do cadastro e some da CONTAGEM de filiais, nunca da lista. -->
                                            <span v-if="filial.ehEntrega" class="tbl-sub">entrega</span>
                                        </td>
                                        <td class="tbl-itens-td">{{ filial.razaoSocial }}</td>
                                        <td class="tbl-itens-td">{{ filial.cnpj ?? '—' }}</td>
                                        <td class="tbl-itens-td">{{ filial.estado ?? '—' }}</td>
                                        <td class="tbl-itens-td">
                                            <StatusPill :tone="TONS_STATUS_CARTEIRA[filial.status]" size="sm">
                                                {{ ROTULOS_STATUS_CARTEIRA[filial.status] }}
                                            </StatusPill>
                                        </td>
                                        <td class="tbl-itens-td">{{ filial.dataUltimaCompra ?? 'Nunca' }}</td>
                                        <td class="tbl-itens-td">
                                            <!-- Os mesmos botões da linha, agora sobre a filial
                                                 de verdade. `filial` já traz id/telefone/email/
                                                 cnpj, que é tudo o que as ações consomem. -->
                                            <div class="tbl-acoes" @click.stop>
                                                <Link
                                                    v-if="podeVerDetalhes"
                                                    :href="route('carteira.detalhes', filial.id)"
                                                    title="Ver detalhes desta filial"
                                                    class="tbl-acao tbl-acao-neutro"
                                                >
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg>
                                                </Link>
                                                <BotoesContato
                                                    v-if="podeLigar"
                                                    :telefone="filial.telefone"
                                                    :email="filial.email"
                                                    @contato="registrarContato(filial, $event)"
                                                />
                                                <button
                                                    v-if="podeAgendar"
                                                    type="button"
                                                    title="Agendar ligação"
                                                    class="tbl-acao tbl-acao-cyan"
                                                    @click="emit('agendar-ligacao', filial)"
                                                >
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                        <rect x="3" y="4.5" width="18" height="16" rx="1.5" />
                                                        <path d="M3 9h18M8 3v3M16 3v3" stroke-linecap="round" stroke-linejoin="round" />
                                                        <path d="m9 15 2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="podeOrcamento"
                                                    type="button"
                                                    title="Criar orçamento para esta filial"
                                                    class="tbl-acao tbl-acao-navy"
                                                    @click="criarOrcamento(filial)"
                                                >
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                        <path d="M7 3h7l4 4v14H7Z" stroke-linecap="round" stroke-linejoin="round" />
                                                        <path d="M14 3v4h4M9.5 13h5M9.5 16.5h5" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                                <button
                                                    v-if="podeObservar"
                                                    type="button"
                                                    title="Observações"
                                                    class="tbl-acao tbl-acao-amber"
                                                    @click="emit('observacao', filial)"
                                                >
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                        <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                                                        <line x1="8" y1="9" x2="16" y2="9" stroke-linecap="round" />
                                                        <line x1="8" y1="12.5" x2="13" y2="12.5" stroke-linecap="round" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <p
                                v-if="filiaisPorCliente[cliente.codCliente].mostrando < filiaisPorCliente[cliente.codCliente].total"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Mostrando as {{ filiaisPorCliente[cliente.codCliente].mostrando }} primeiras de
                                {{ filiaisPorCliente[cliente.codCliente].total }} lojas. Para a lista completa, use o Excel.
                            </p>
                        </template>
                    </td>
                </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
