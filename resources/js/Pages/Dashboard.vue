<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import StatusPill from '@/Components/StatusPill.vue';
import VisaoSelector from '@/Components/Dashboard/VisaoSelector.vue';
import MetaGaugeCard from '@/Components/Dashboard/MetaGaugeCard.vue';
import CarteiraSegmentoCard from '@/Components/Dashboard/CarteiraSegmentoCard.vue';
import LigacoesStatsCards from '@/Components/Dashboard/LigacoesStatsCards.vue';
import OrcamentosStatsCard from '@/Components/Dashboard/OrcamentosStatsCard.vue';
import PedidosAtencaoCard from '@/Components/Dashboard/PedidosAtencaoCard.vue';
import ComparacaoCard from '@/Components/Dashboard/ComparacaoCard.vue';
import PotencialCarteiraCard from '@/Components/Dashboard/PotencialCarteiraCard.vue';
import FaturamentoBiEmbed from '@/Components/Dashboard/FaturamentoBiEmbed.vue';
import SugestoesBoard from '@/Components/Dashboard/SugestoesBoard.vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({
    role: String,
    statusSistema: Array,
    statusCache: Object,
    visao: Object,
    metaGauge: Object,
    ligacoesStats: Object,
    observacoesStats: Object,
    vendaComparacao: Object,
    faturamentoComparacao: Object,
    biEmbedUrl: String,
    carteiraSegmento: Object,
    orcamentosStats: Object,
    pedidosAtencao: Object,
    potencialCarteira: Object,
    segmentosVendedor: { type: Array, default: () => [] },
});

const page = usePage();

const tonsStatus = {
    atualizado: 'ok',
    atencao: 'warn',
    desatualizado: 'danger',
};

const labelsStatus = {
    atualizado: 'Atualizado',
    atencao: 'Atenção',
    desatualizado: 'Desatualizado',
};

// Uma métrica só pro sistema como um todo — o pior status entre as tabelas
// sincronizadas, não uma pill por tabela.
const prioridadeStatus = { desatualizado: 3, atencao: 2, atualizado: 1 };

const statusGeral = computed(() => {
    if (!props.statusSistema.length) return null;

    const pior = props.statusSistema.reduce((acc, item) =>
        (prioridadeStatus[item.status] || 0) > (prioridadeStatus[acc.status] || 0) ? item : acc,
    );

    return {
        tom: tonsStatus[pior.status] || 'neutral',
        label: labelsStatus[pior.status] || 'Verificando…',
    };
});

// Pill de fogo: estado do cache warming (só admin). Serve pra que um worker parado seja
// VISÍVEL — sem ela, o cache esfria em silêncio e a lentidão só aparece como reclamação.
const cacheWarming = computed(() => {
    if (!props.statusCache) return null;

    const mapa = {
        aquecido: { tom: 'ok', label: 'Cache aquecido' },
        esfriando: { tom: 'warn', label: 'Cache esfriando' },
        frio: { tom: 'danger', label: 'Cache frio' },
        // Estado normal em dev: o scheduler só roda com cron de verdade.
        ausente: { tom: 'neutral', label: 'Cache sem warming' },
    };

    const item = mapa[props.statusCache.status] || mapa.ausente;
    const minutos = props.statusCache.minutos;

    return {
        ...item,
        titulo: minutos === null
            ? 'Nenhum aquecimento registrado nas últimas 24h'
            : `Último aquecimento há ${minutos} min`,
    };
});

const mesAno = computed(() => {
    const meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const agora = new Date();
    return `${meses[agora.getMonth()]} ${agora.getFullYear()}`;
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Painel Comercial">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 20V4" stroke-linecap="round" />
                            <path d="M4 20h16" stroke-linecap="round" />
                            <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Olá, <strong class="font-semibold text-gray-100">{{ page.props.auth.user.display_name || page.props.auth.user.name }}</strong>
                        · {{ mesAno }} · dados referentes ao dia anterior (D-1)
                    </template>
                    <template #meta>
                        <!--
                            Identidade: o vendedor vê a que segmento a carteira dele
                            responde. Aqui vai COM o rótulo "Segmento:", porque no topo da
                            página o chip aparece fora de contexto; dentro do card de
                            Carteira por Segmento ele entra sem rótulo, já contextualizado.
                        -->
                        <StatusPill v-if="segmentosVendedor.length" tone="neutral" surface="dark" :title="segmentosVendedor.join(', ')">
                            Segmento: {{ segmentosVendedor[0] }}<template v-if="segmentosVendedor.length > 1"> +{{ segmentosVendedor.length - 1 }}</template>
                        </StatusPill>
                        <StatusPill v-if="statusGeral" :tone="statusGeral.tom" surface="dark">
                            Sistema: {{ statusGeral.label }}
                        </StatusPill>
                        <StatusPill
                            v-if="cacheWarming"
                            :tone="cacheWarming.tom"
                            surface="dark"
                            :title="cacheWarming.titulo"
                        >
                            <template #icon>
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-full w-full">
                                    <path d="M12 2.5c.3 3-1.2 4.3-2.6 5.6C7.9 9.5 6.5 10.8 6.5 14a5.5 5.5 0 0 0 11 0c0-2.4-1-4-2-5.3-.3 1-1 1.8-1.8 2.1.5-2.4-.4-5.9-1.7-8.3Zm0 17a2.6 2.6 0 0 1-2.6-2.6c0-1.5.9-2.2 1.6-2.9.5-.5 1-1 1.2-1.8.9.9 2.4 2.2 2.4 4.7A2.6 2.6 0 0 1 12 19.5Z" />
                                </svg>
                            </template>
                            {{ cacheWarming.label }}
                        </StatusPill>
                    </template>
                    <template v-if="visao.mostrarSeletor" #filtros>
                        <VisaoSelector :visao="visao" />
                    </template>
                </PageHero>

                <!--
                    ⚠️ ORDEM DA PÁGINA = HIERARQUIA DE IMPORTÂNCIA, revisada com o Tony em
                    2026-09-05. O primeiro bloco abaixo do cabeçalho é o que o usuário vê
                    sem rolar, e ele muda por perfil:
                      · vendedor/representante → POTENCIAL DA CARTEIRA (onde há venda a
                        fazer hoje — é o motivo de a página existir para quem opera);
                      · gestor → o embed do Power BI, que é a ferramenta dele.
                    A comparação ano contra ano saiu da primeira dobra e passou a fechar o
                    bloco de performance, logo abaixo de Carteira por Segmento e Performance
                    Comercial: é o histórico que dá contexto àqueles dois números, então mora
                    junto deles — mas depois, porque é leitura de apoio e não pauta do dia.

                    ⚠️ NÃO empilhar dois cards numa coluna deste grid. O grid é
                    `items-stretch`; um wrapper flex-col aqui recebe a altura da coluna mais
                    alta e ENCOLHE os cards para caberem — foi o que cortou 44px do bloco
                    "Pedidos emitidos" quando o Potencial morava aqui. Card novo entra como
                    faixa própria, não empilhado.
                -->
                <FaturamentoBiEmbed v-if="biEmbedUrl" :url="biEmbedUrl" />
                <PotencialCarteiraCard
                    v-else-if="potencialCarteira"
                    :potencial-carteira="potencialCarteira"
                    :visao-supervisor="visao.visaoSupervisor"
                    :visao-vendedor="visao.visaoVendedor"
                />

                <div v-if="carteiraSegmento || metaGauge" class="grid gap-4 lg:grid-cols-2 lg:items-stretch">
                    <CarteiraSegmentoCard
                        v-if="carteiraSegmento"
                        :carteira-segmento="carteiraSegmento"
                        :segmentos="segmentosVendedor"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <MetaGaugeCard
                        v-if="metaGauge"
                        :meta-gauge="metaGauge"
                        :role="role"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                </div>

                <ComparacaoCard
                    v-if="vendaComparacao || faturamentoComparacao"
                    :venda-comparacao="vendaComparacao"
                    :faturamento-comparacao="faturamentoComparacao"
                />

                <div v-if="ligacoesStats || orcamentosStats || pedidosAtencao" class="grid gap-4 lg:grid-cols-3 lg:items-stretch">
                    <LigacoesStatsCards
                        v-if="ligacoesStats"
                        :ligacoes-stats="ligacoesStats"
                        :observacoes-stats="observacoesStats"
                        :role="role"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <OrcamentosStatsCard
                        v-if="orcamentosStats"
                        :orcamentos-stats="orcamentosStats"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <PedidosAtencaoCard
                        v-if="pedidosAtencao"
                        :pedidos-atencao="pedidosAtencao"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                </div>

                <SugestoesBoard :role="role" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
