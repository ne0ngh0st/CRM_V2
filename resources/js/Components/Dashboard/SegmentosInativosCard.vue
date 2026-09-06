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
 * ⚠️ As linhas são os segmentos ONDE ELE TEM CLIENTE INATIVO, não os atribuídos a ele no
 * cadastro. Medido em 2026-09-06: 24 dos 142 vendedores com segmento atribuído (16,9%)
 * têm ZERO inativo no próprio segmento — pela regra do cadastro o card inteiro viraria
 * uma linha escrita "Outros". Na média só 41,7% dos inativos caem no segmento atribuído.
 *
 * ⚠️ A linha "Demais segmentos" (cauda além do teto de 6) não é enfeite: sem ela o TOTAL
 * não fecharia com a soma das linhas.
 *
 * ⚠️ Sem link para a Carteira: o filtro de lá recorta por segmento pelo CÓDIGO, e este
 * quadro agrupa por nome já resolvido. Ligar os dois exigiria devolver o código junto —
 * decisão para quando a regra de família voltar e o quadro ganhar as colunas de produto.
 */
import { computed } from 'vue';
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
 * ⚠️ Linha sem código (a cauda "Demais segmentos") não vira link: nenhum filtro da
 * Carteira representa "o resto", e link que leva ao lugar errado é pior que linha estática.
 */
function href(linha) {
    if (linha.outros || !linha.codigo) {
        return null;
    }

    return route('carteira.index', {
        segmento: linha.codigo,
        status: 'inativo',
        visao_supervisor: props.visaoSupervisor || undefined,
        visao_vendedor: props.visaoVendedor || undefined,
    });
}

const temLinhas = computed(() => props.segmentosInativos.linhas.length > 0);

const subtitulo = computed(() => {
    const total = props.segmentosInativos.total;

    return `${total} ${total === 1 ? 'cliente inativo' : 'clientes inativos'} na carteira`;
});

const explicacao = [
    'Clientes sem compra há mais de 365 dias, ou que nunca compraram.',
    '',
    'Clique numa linha para abrir a Carteira já filtrada naquele segmento.',
    '',
    'As linhas são os segmentos onde você tem cliente inativo, do maior para o menor.',
    '"Demais segmentos" reúne a cauda além das 6 primeiras linhas — existe para o total fechar com a sua carteira.',
    '',
    'Conta TODOS os clientes inativos do escopo.',
    'O card "Carteira por Segmento" pode mostrar um número menor: os quadrinhos de lá deixam de fora os clientes cujo vendedor não tem segmento cadastrado, porque ali a pergunta é de aderência. Aqui a pergunta é quantos inativos existem, e nenhum fica de fora.',
].join('\n');

const formatar = new Intl.NumberFormat('pt-BR');
</script>

<template>
    <DarkCard title="Segmentos Atendidos" :subtitle="subtitulo">
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
                        v-for="linha in segmentosInativos.linhas"
                        :key="linha.nome"
                        :href="href(linha) ?? undefined"
                        :as="href(linha) ? 'tr' : undefined"
                        class="tbl-row"
                        :class="href(linha) ? 'group cursor-pointer' : ''"
                        :title="href(linha) ? `Ver os ${linha.inativos} inativos de ${linha.nome} na Carteira` : null"
                    >
                        <td
                            class="tbl-td text-left"
                            :class="linha.outros ? 'italic text-gray-500' : 'font-medium text-gray-800'"
                        >
                            {{ linha.nome }}
                        </td>
                        <td
                            class="tbl-td text-right tabular-nums"
                            :class="linha.outros ? 'text-gray-500' : 'font-semibold text-gray-800'"
                        >
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
                        <td class="tbl-td text-right text-base font-bold tabular-nums text-navy">
                            {{ formatar.format(segmentosInativos.total) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-else class="text-sm text-gray-400">Nenhum cliente inativo nesta carteira.</p>
    </DarkCard>
</template>
