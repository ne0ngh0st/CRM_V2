<script setup>
/**
 * Segmentos atendidos × clientes inativos.
 *
 * Substituiu o quadro de Potencial por família de produto em 2026-09-06, a pedido do
 * diretor: "MENOS É MAIS, nada de análises complicadas, simples e direto". O quadro
 * anterior trazia, por família, total / ativos / inativos / cobertura / peso — cinco
 * números por painel, três painéis. Este traz um número por linha.
 *
 * ⚠️ O TOTAL DAQUI BATE COM O "INATIVOS" DO CARD AO LADO, sempre e em todo escopo — mesmo
 * corte de 365 dias, mesmo universo. É exigência do Tony (08/09/2026): "os dois números têm
 * que bater em todos os casos". Uma versão intermediária do mesmo dia listava só os
 * segmentos atendidos e mostrava 66.753 ao lado de 73.940; explicar a diferença no
 * subtítulo não resolveu, porque número que precisa de legenda para não parecer errado já
 * custou a confiança. Nunca reintroduzir filtro de linha aqui: o "ver mais" filtra a
 * EXIBIÇÃO, jamais o somatório.
 *
 * ⚠️ Os segmentos que a pessoa atende vêm MARCADOS na listagem (pedido do diretor em
 * 08/09), e o atendido sem nenhum inativo aparece com zero em vez de sumir. Marcar em vez
 * de filtrar é o que atende os dois pedidos ao mesmo tempo.
 *
 * ⚠️ POTENCIAL = inativos × peso do segmento, EM CAIXAS (Tony, 08/09/2026) — o peso é
 * quantas caixas um cliente daquele segmento tende a comprar. A unidade aparece na tela
 * sempre: todo outro número grande do Painel é em reais, então potencial sem unidade seria
 * lido como dinheiro. A conta mora no `SegmentosInativosResolver`; aqui só se exibe, com o
 * peso impresso embaixo do número — potencial sem a conta à vista é número que ninguém
 * confere, e a tabela ordena por ele.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';

const props = defineProps({
    segmentosInativos: { type: Object, required: true },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

/**
 * Cada linha abre a Carteira já filtrada naquele segmento e no status Inativo.
 *
 * ⚠️ Os números BATEM com o destino: o filtro `status=inativo` da Carteira usa o mesmo
 * corte de 365 dias do `ClienteStatusResolver` que este quadro, e `segmento` compara o
 * mesmo `clientes.cod_segmento`. Se um dia divergirem, o vendedor clica em 53 e encontra
 * outra quantidade — que é como ele deixa de confiar na tela.
 *
 * ⚠️ Linha sem código (cliente sem segmento no cadastro) não vira link: o filtro da
 * Carteira compara o código, e um link vazio levaria à carteira inteira — pior que linha
 * estática.
 */
function href(linha) {
    if (!linha.codigo) {
        return null;
    }

    return route('carteira.index', {
        segmento: linha.codigo,
        status: 'inativo',
        visao_supervisor: props.visaoSupervisor || undefined,
        visao_vendedor: props.visaoVendedor || undefined,
    });
}

const formatar = new Intl.NumberFormat('pt-BR');

const temLinhas = computed(() => props.segmentosInativos.linhas.length > 0);

const subtitulo = computed(() => {
    const { total, atendidos, linhas } = props.segmentosInativos;

    if (linhas.length === 0) {
        return 'Nenhum cliente inativo na carteira';
    }

    const inativos = `${formatar.format(total)} ${total === 1 ? 'cliente inativo' : 'clientes inativos'}`;

    // Diz quantos segmentos são dela porque a listagem mistura os dois tipos de linha — sem
    // isso a marca de "atendido" fica sem referência de quantidade.
    if (atendidos === 0) {
        return `${inativos} · nenhum segmento atribuído a você`;
    }

    return `${inativos} · ${atendidos} ${atendidos === 1 ? 'segmento seu' : 'segmentos seus'} `
        + `de ${linhas.length}`;
});

const explicacao = [
    'Clientes sem compra há mais de 365 dias, ou que nunca compraram.',
    '',
    'Clique numa linha para abrir a Carteira já filtrada naquele segmento.',
    '',
    'A lista traz TODOS os segmentos em que você tem cliente inativo. Os que você atende vêm marcados com o ponto colorido.',
    'Segmento seu sem nenhum inativo aparece com zero — quer dizer que está em dia.',
    'Mostra os 3 maiores; "ver mais" abre os demais. Os TOTAIS são sempre da carteira inteira, esteja a lista aberta ou não.',
    '',
    'POTENCIAL, EM CAIXAS = clientes inativos × caixas por cliente do segmento.',
    'O peso (0 a 20 caixas) foi definido pela diretoria: é quanto um cliente daquele segmento tende a comprar.',
    'Peso 0 significa que o segmento não é alvo de reativação — por isso o potencial dele é 0 mesmo com muitos inativos.',
    'A tabela é ordenada pelo potencial, não pela quantidade.',
    '',
    'O TOTAL de inativos é o mesmo número do card "Carteira por Segmento" — nenhum cliente fica de fora da conta.',
].join('\n');

/**
 * O quadro mostra os 3 maiores e abre o resto no clique — pedido do Tony em 2026-09-06:
 * "mostra 3 e se quiser expande e vê o resto".
 *
 * ⚠️ O corte é de EXIBIÇÃO, não de dado: o servidor manda todos os segmentos, o TOTAL do
 * rodapé é sempre da carteira inteira, e "ver mais" não vai ao servidor.
 */
const VISIVEIS = 3;

const aberto = ref(false);

const linhasVisiveis = computed(() => (aberto.value
    ? props.segmentosInativos.linhas
    : props.segmentosInativos.linhas.slice(0, VISIVEIS)));

const ocultas = computed(() => Math.max(0, props.segmentosInativos.linhas.length - VISIVEIS));

const maiorPotencial = computed(
    () => props.segmentosInativos.linhas.reduce((maior, l) => Math.max(maior, l.potencial), 0),
);

/**
 * Peso inteiro sai sem casas ("8", não "8,00"); só mostra decimal se a diretoria mandar um
 * peso quebrado algum dia. A coluna é estreita e "8,00" polui sem informar nada.
 */
/**
 * Largura da barra da linha, proporcional ao MAIOR potencial da lista.
 *
 * ⚠️ Relativo ao maior, e não ao total: a lista pode ter 20+ segmentos, e aí fatia do
 * total daria barra de 2px em todas as linhas — desenho sem leitura. Assim a primeira
 * linha é a régua e as outras se leem contra ela, que é a pergunta do quadro ("por onde
 * começo"). Potencial 0 fica com barra vazia de propósito: é o segmento que a diretoria
 * marcou como fora do alvo.
 */
function larguraBarra(linha) {
    if (maiorPotencial.value <= 0) {
        return '0%';
    }

    // Piso de 2% para o que tem potencial > 0 não desaparecer ao lado de um líder muito
    // maior — barra invisível se confunde com peso 0, que é outra coisa.
    const pct = (linha.potencial / maiorPotencial.value) * 100;

    return `${linha.potencial > 0 ? Math.max(pct, 2) : 0}%`;
}

function formatarPeso(peso) {
    return Number.isInteger(peso) ? String(peso) : formatar.format(peso);
}
</script>

<template>
    <DarkCard title="Segmentos Atendidos" :subtitle="subtitulo" colapsavel chave-colapso="segmentos-inativos">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                <line x1="4" y1="18" x2="14" y2="18" stroke-linecap="round" />
            </svg>
        </template>

        <template #actions>
            <span
                class="inline-flex h-5 w-5 cursor-help items-center justify-center rounded-full border border-gray-500 text-[0.7rem] font-bold text-gray-300 transition hover:border-white hover:text-white"
                :title="explicacao"
            >i</span>
        </template>

        <div v-if="temLinhas" class="overflow-x-auto">
            <!--
                ⚠️ Esta tabela NÃO usa os tokens `.tbl*` (Regra de ouro nº 5), e isso é
                deliberado: aqueles são de tabela de dados de página cheia — células
                centradas e `divide-x`. Aqui é legenda dentro de card, e num card de faixa
                inteira (1800px) o `divide-x` desenhava duas réguas verticais no meio do
                vazio e os números paravam no centro da faixa, longe do nome que explicam.
                Mesma razão que já mantém a mini-tabela do `CarteiraSegmentoCard` fora dos
                tokens — a linguagem de card é esta: alinhamento esquerda/direita, filete
                claro entre linhas, sem divisória vertical.

                ⚠️ A BARRA existe para o card não ficar 60% vazio numa faixa desta largura:
                ocupa a folga entre o nome e os números com informação em vez de espaço, e
                mostra de relance a ordem por potencial. É proporcional ao MAIOR potencial
                da lista (ranking), não ao total — com 20+ segmentos, fatia do total viraria
                fiapo invisível em todas as linhas.
            -->
            <table class="w-full min-w-[520px] text-sm">
                <!--
                    ⚠️ A FOLGA DA LARGURA VAI PARA A BARRA, não para o nome. A primeira
                    versão deixava a coluna do nome absorver a sobra e, numa faixa de
                    1800px, reaparecia o defeito original: ~700px de vazio entre o nome e o
                    resto da linha. Com o nome preso em 22% (o maior segmento tem 28
                    caracteres, cabe folgado, e `table-auto` ainda estica se algum crescer)
                    quem estica é a coluna sem largura — a da barra.
                -->
                <colgroup>
                    <col class="w-[22%]" />
                    <col />
                    <col class="w-[150px]" />
                    <col class="w-[130px]" />
                </colgroup>
                <thead>
                    <tr class="text-[0.65rem] uppercase tracking-wide text-gray-400">
                        <th class="pb-1 text-left font-semibold">Segmento</th>
                        <th class="pb-1" />
                        <!--
                            ⚠️ POTENCIAL vem antes de inativos e é o número em destaque: é
                            ele que ordena a tabela e responde "por onde começo". Inativos
                            fica à direita, em tom de apoio, como o insumo da conta.
                        -->
                        <th class="pb-1 text-right font-semibold">
                            Potencial <span class="font-normal normal-case text-gray-400">(cx)</span>
                        </th>
                        <th class="pb-1 text-right font-semibold">Clientes inativos</th>
                    </tr>
                </thead>
                <tbody>
                    <!--
                        ⚠️ A LINHA INTEIRA é o alvo de clique, não só o número. Foi a lição
                        de 2026-09-05: um link de 60×21px funcionava e mesmo assim ninguém
                        acertava nele. O fundo que acende no hover e a seta que aparece à
                        direita anunciam "isto abre algo" sem underline em cada célula.
                    -->
                    <component
                        :is="href(linha) ? Link : 'tr'"
                        v-for="linha in linhasVisiveis"
                        :key="linha.nome"
                        :href="href(linha) ?? undefined"
                        :as="href(linha) ? 'tr' : undefined"
                        class="border-t border-gray-100 transition"
                        :class="href(linha) ? 'group cursor-pointer hover:bg-gray-50' : ''"
                        :title="href(linha) ? `${linha.potencial} caixas = ${linha.inativos} clientes inativos × ${linha.peso} caixas por cliente. Clique para ver esses clientes na Carteira.` : null"
                    >
                        <!--
                            ⚠️ Ponto teal = segmento que a pessoa atende (pedido do diretor,
                            08/09). O ponto RESERVA ESPAÇO também quando ausente
                            (`invisible`, não `v-if`): sem isso os nomes das linhas não
                            atendidas começam deslocados e a coluna vira um zigue-zague.
                        -->
                        <!--
                            ⚠️ `whitespace-nowrap`: os 22% da coluna são folga em tela
                            cheia, mas em card estreito o nome de 22+ caracteres quebrava em
                            duas linhas e só AQUELA linha ficava mais alta que as vizinhas.
                            Com nowrap o navegador respeita o min-content e alarga a coluna;
                            se nem assim couber, quem rola é o `overflow-x-auto` de fora.
                        -->
                        <td class="whitespace-nowrap py-1.5 pr-3">
                            <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                                <span
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-teal"
                                    :class="linha.atendido ? '' : 'invisible'"
                                    :title="linha.atendido ? 'Segmento que você atende' : null"
                                />
                                {{ linha.nome }}
                            </span>
                        </td>
                        <td class="py-1.5 pr-4">
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="h-full rounded-full"
                                    :class="linha.atendido ? 'bg-teal' : 'bg-gray-300'"
                                    :style="{ width: larguraBarra(linha) }"
                                />
                            </div>
                        </td>
                        <!--
                            ⚠️ O peso aparece embaixo do número, em miúdo. Sem ele o
                            potencial é um número que caiu do céu: com ele o vendedor lê
                            "188 × 8" e confere de cabeça — e entende por que o segmento de
                            peso 0 fica no fim da lista mesmo cheio de inativo.
                        -->
                        <td class="py-1.5 text-right">
                            <span class="block font-semibold leading-4 tabular-nums text-navy">
                                {{ formatar.format(linha.potencial) }}
                            </span>
                            <span class="block text-[0.65rem] leading-3 text-gray-400">
                                {{ formatarPeso(linha.peso) }} cx/cliente
                            </span>
                        </td>
                        <td class="py-1.5 text-right tabular-nums text-gray-600">
                            <span class="inline-flex items-center justify-end gap-1">
                                {{ formatar.format(linha.inativos) }}
                                <svg
                                    v-if="href(linha)"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="h-3 w-3 text-navy opacity-0 transition group-hover:opacity-100"
                                >
                                    <path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <!-- Reserva o espaço da seta para o número não dançar no hover. -->
                                <span v-else class="h-3 w-3" />
                            </span>
                        </td>
                    </component>
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-300">
                        <td class="py-2 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">
                            Total
                        </td>
                        <td class="py-2" />
                        <td class="py-2 text-right">
                            <span class="font-bold tabular-nums text-navy">
                                {{ formatar.format(segmentosInativos.totalPotencial) }}
                            </span>
                            <span class="ml-1 text-[0.65rem] text-gray-400">cx</span>
                        </td>
                        <td class="py-2 text-right font-semibold tabular-nums text-gray-600">
                            {{ formatar.format(segmentosInativos.total) }}
                        </td>
                    </tr>
                </tfoot>
            </table>

            <button
                v-if="ocultas > 0"
                type="button"
                class="mt-2 flex w-full items-center justify-center gap-1 text-xs font-medium text-gray-500 transition hover:text-navy"
                :aria-expanded="aberto"
                @click="aberto = !aberto"
            >
                {{ aberto ? 'Ver menos' : `Ver mais ${ocultas} ${ocultas === 1 ? 'segmento' : 'segmentos'}` }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 transition-transform" :class="aberto ? 'rotate-180' : ''">
                    <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        </div>

        <p v-else class="text-sm text-gray-400">
            Nenhum segmento cadastrado para este escopo — fale com o admin para vincular
            seus segmentos.
        </p>
    </DarkCard>
</template>
