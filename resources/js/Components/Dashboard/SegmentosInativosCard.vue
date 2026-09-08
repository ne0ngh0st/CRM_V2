<script setup>
/**
 * Segmentos atendidos × clientes inativos.
 *
 * Substituiu o quadro de Potencial por família de produto em 2026-09-06, a pedido do
 * diretor: "MENOS É MAIS, nada de análises complicadas, simples e direto". O quadro
 * anterior trazia, por família, total / ativos / inativos / cobertura / peso — cinco
 * números por painel, três painéis. Este traz um número por linha.
 *
 * ⚠️ "Inativo" aqui usa o MESMO CORTE da Carteira (365 dias sem compra, ou nunca), mas o
 * UNIVERSO é maior: este card conta todos os inativos do escopo, enquanto os quadrinhos
 * do card "Carteira por Segmento" excluem os clientes cujo vendedor não tem segmento
 * cadastrado — lá a pergunta é de aderência, e esse terceiro balde fica à parte.
 *
 * Para um vendedor com segmento cadastrado os dois números batem (medido: 188 = 188).
 * Em escopo de equipe ou empresa eles divergem, e muito (73.935 contra 21.619 na base de
 * dev). O `i` do card explica isso na tela — sem essa explicação são dois "Inativos"
 * diferentes lado a lado, que é como o usuário deixa de confiar na tela inteira.
 *
 * ⚠️ As linhas são os segmentos que a pessoa ATENDE (cadastro em `segmentos_vendedor`), e
 * só eles — o nome do card é literal. Segmento onde ela tem inativo mas que não é dela
 * fica de fora, e quem responde por esses é o card "Carteira por Segmento", no balde
 * "fora do segmento".
 *
 * ⚠️ Segmento atendido sem nenhum inativo aparece COM ZERO. Medido em 2026-09-06: 24 dos
 * 142 vendedores com segmento cadastrado estão nessa situação e vão ver o card todo em
 * zero. É a resposta certa — o segmento de cadastro está em dia — e não um card quebrado.
 *
 * ⚠️ POTENCIAL = inativos × peso do segmento, EM CAIXAS (Tony, 08/09/2026) — o peso é
 * quantas caixas um cliente daquele segmento tende a comprar. A unidade aparece na tela
 * sempre: todo outro número grande do Painel é em reais, então potencial sem unidade seria
 * lido como dinheiro. A conta mora no `SegmentosInativosResolver`; aqui só se exibe, com o
 * peso impresso embaixo do número — potencial sem a conta à vista é número que ninguém
 * confere, e a tabela ordena por ele.
 *
 * ⚠️ O subtítulo diz "N dos M inativos" porque os dois cards da fileira contam universos
 * diferentes: aqui só os segmentos atendidos, no "Carteira por Segmento" a carteira
 * inteira. Ver 0 aqui e 188 ali sem explicação é como o vendedor deixa de confiar na tela —
 * reclamação do Tony em 06/09 e de novo em 08/09. O M vem do próprio bloco
 * (`totalCarteira`), não de outro card, para os dois não poderem divergir por caminho.
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
    const { total, totalCarteira, linhas } = props.segmentosInativos;
    const n = linhas.length;

    if (n === 0) {
        return 'Nenhum segmento cadastrado para você';
    }

    const onde = n === 1 ? 'no seu segmento' : `nos seus ${n} segmentos`;

    // Só compara quando há diferença: "188 dos 188" é ruído, e é o caso de quem tem a
    // carteira inteira dentro do próprio segmento.
    return total === totalCarteira
        ? `${formatar.format(total)} ${total === 1 ? 'cliente inativo' : 'clientes inativos'} ${onde}`
        : `${formatar.format(total)} dos ${formatar.format(totalCarteira)} clientes inativos da carteira estão ${onde}`;
});

const explicacao = [
    'Clientes sem compra há mais de 365 dias, ou que nunca compraram.',
    '',
    'Clique numa linha para abrir a Carteira já filtrada naquele segmento.',
    '',
    'As linhas são os segmentos que VOCÊ atende, do maior para o menor.',
    'Segmento seu sem nenhum inativo aparece com zero — quer dizer que está em dia.',
    'Cliente inativo fora dos seus segmentos não entra aqui; ele está no card Carteira por Segmento, no balde "fora do segmento".',
    'Mostra os 3 maiores; "ver mais" abre os demais. Os TOTAIS são sempre da carteira inteira, esteja a lista aberta ou não.',
    '',
    'POTENCIAL, EM CAIXAS = clientes inativos × caixas por cliente do segmento.',
    'O peso (0 a 20 caixas) foi definido pela diretoria: é quanto um cliente daquele segmento tende a comprar.',
    'Peso 0 significa que o segmento não é alvo de reativação — por isso o potencial dele é 0 mesmo com muitos inativos.',
    'A tabela é ordenada pelo potencial, não pela quantidade.',
    '',
    'O TOTAL é a soma das linhas: inativos dentro dos seus segmentos. O card "Carteira por Segmento" mostra a carteira inteira, por isso o número de lá é maior.',
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

/**
 * Peso inteiro sai sem casas ("8", não "8,00"); só mostra decimal se a diretoria mandar um
 * peso quebrado algum dia. A coluna é estreita e "8,00" polui sem informar nada.
 */
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

        <div v-if="temLinhas" class="tbl-wrap">
            <table class="tbl min-w-[320px]">
                <thead>
                    <tr class="tbl-head-row">
                        <th class="tbl-th text-left">Segmento</th>
                        <!--
                            ⚠️ POTENCIAL vem PRIMEIRO e é o número em destaque: é ele que
                            ordena a tabela e responde "por onde começo". Inativos fica à
                            direita, em tom de apoio, como o insumo da conta.
                        -->
                        <th class="tbl-th text-right">Potencial <span class="font-normal normal-case text-gray-400">(caixas)</span></th>
                        <th class="tbl-th text-right">Clientes inativos</th>
                    </tr>
                </thead>
                <tbody class="tbl-body">
                    <!--
                        ⚠️ A LINHA INTEIRA é o alvo de clique, não só o número. Foi a lição
                        de 2026-09-05: um link de 60×21px funcionava e mesmo assim ninguém
                        acertava nele. O fundo que acende no hover e a seta que aparece à
                        direita anunciam "isto abre algo" sem precisar de underline em cada
                        célula — que é o "sem ser too much" pedido.
                    -->
                    <component
                        :is="href(linha) ? Link : 'tr'"
                        v-for="linha in linhasVisiveis"
                        :key="linha.nome"
                        :href="href(linha) ?? undefined"
                        :as="href(linha) ? 'tr' : undefined"
                        class="tbl-row"
                        :class="href(linha) ? 'group cursor-pointer' : ''"
                        :title="href(linha) ? `${linha.potencial} caixas = ${linha.inativos} clientes inativos × ${linha.peso} caixas por cliente. Clique para ver esses clientes na Carteira.` : null"
                    >
                        <td class="tbl-td text-left font-medium text-gray-800">
                            <!--
                                ⚠️ Sem marca de "atendido": desde 2026-09-08 TODA linha é um
                                segmento atendido, então o destaque marcaria tudo — e marca
                                que sempre aparece não destaca nada.
                            -->
                            {{ linha.nome }}
                        </td>
                        <!--
                            ⚠️ O peso aparece embaixo do número, em miúdo. Sem ele o
                            potencial é um número que caiu do céu: com ele o vendedor lê
                            "188 × 8" e confere de cabeça — e entende por que o segmento de
                            peso 0 fica no fim da lista mesmo cheio de inativo.
                        -->
                        <td class="tbl-td text-right">
                            <span class="block font-semibold leading-4 tabular-nums text-navy">
                                {{ formatar.format(linha.potencial) }}
                            </span>
                            <span class="block text-[0.65rem] leading-3 text-gray-400">
                                {{ formatarPeso(linha.peso) }} cx/cliente
                            </span>
                        </td>
                        <td class="tbl-td text-right tabular-nums text-gray-600">
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
                    <tr class="border-t-2 border-gray-300 bg-gray-50">
                        <td class="tbl-td text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500">
                            Total
                        </td>
                        <td class="tbl-td text-right">
                            <span class="text-base font-bold tabular-nums text-navy">
                                {{ formatar.format(segmentosInativos.totalPotencial) }}
                            </span>
                            <span class="ml-1 text-[0.65rem] text-gray-500">cx</span>
                        </td>
                        <td class="tbl-td text-right text-sm font-semibold tabular-nums text-gray-600">
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
