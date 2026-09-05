<script setup>
/**
 * Potencial da Carteira: quantos clientes ainda não compram cada família de produto
 * (bobina, etiqueta, tag de gôndola).
 *
 * ⚠️ É o PRIMEIRO bloco da página para vendedor/representante: é onde há venda a fazer.
 * Por isso ocupa a largura inteira e as famílias são três painéis lado a lado — em largura
 * total uma tabela de três linhas deixaria metade do card vazio.
 *
 * ⚠️ O número grande é a CARTEIRA INTEIRA, e vem quebrado em ATIVOS × INATIVOS, porque são
 * duas conversas diferentes: "você já me compra, leve também etiqueta" e "faz um ano que
 * você não compra nada". A quebra também é o que preserva o sinal de qual família atacar —
 * quem está inativo não compra nenhuma das três, então entra igual nas três e achata a
 * comparação (172/172/149 no total contra 40/40/17 entre os ativos, medido em dev).
 *
 * ⚠️ Cada metade da quebra é um LINK, e o número grande NÃO é. Cada link leva exatamente
 * ao seu próprio conjunto: "ativos" abre a Carteira recortada em quem compra mas não leva
 * a família; "inativos" abre os inativos. Fazer o total clicar levaria a uma lista que não
 * bate com ele.
 *
 * ⚠️ Os rótulos das famílias vêm do SERVIDOR (`familias[].rotulo`). A fonte é
 * App\Services\Potencial\FamiliaProduto — uma cópia aqui é como a Carteira/Detalhes passou
 * a exibir a string crua `pendente_totvs` quando um valor novo entrou no enum de status.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';

const props = defineProps({
    potencialCarteira: { type: Object, required: true },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

const visao = computed(() => ({
    visao_supervisor: props.visaoSupervisor || undefined,
    visao_vendedor: props.visaoVendedor || undefined,
}));

/** Ativos que não levam esta família — o recorte de venda cruzada. */
function hrefAtivos(familia) {
    return route('carteira.index', { sem_familia: familia, ...visao.value });
}

/**
 * Inativos. Reusa o filtro de status que a Carteira já tem, e por isso a contagem lá pode
 * diferir um pouco: aqui "inativo" é "sem nota nos últimos 12 meses"; o status da Carteira
 * corta em 365 dias sobre `data_ultima_compra`, com a faixa "inativando" entre 291 e 365.
 * É a mesma pergunta com a borda ligeiramente diferente — e a Carteira lista filiais, não
 * códigos de cliente.
 */
const hrefInativos = computed(() => route('carteira.index', { status: 'inativo', ...visao.value }));

const temCarteira = computed(() => props.potencialCarteira.carteira > 0);

const subtitulo = computed(() => {
    const { carteira, ativos, janelaMeses } = props.potencialCarteira;

    return `${carteira} clientes · ${ativos} compraram nos últimos ${janelaMeses} meses`;
});

/** Maior oportunidade de venda cruzada primeiro: é a parte acionável hoje. */
const familiasOrdenadas = computed(() =>
    [...props.potencialCarteira.familias].sort((a, b) => b.potencialAtivos - a.potencialAtivos),
);

const explicacao = computed(() => {
    const { janelaMeses } = props.potencialCarteira;

    return [
        `Clientes da sua carteira que não compraram a família nos últimos ${janelaMeses} meses.`,
        '',
        'ATIVOS — compraram algo de você no período, mas não esta família. É venda cruzada: o cliente já é seu.',
        '',
        'INATIVOS — não compraram nada de você no período. É reativação.',
        '',
        'O número de inativos é IGUAL nas três famílias, e isso é esperado: quem não compra nada não compra nenhuma delas.',
        '',
        'A família sai da categoria cadastrada no TOTVS; suprimentos (sacola, papel A4, café) não entram na conta.',
        'Conta o código do cliente (a empresa), não cada filial — a nota fiscal não registra a loja.',
        'Conta o que foi vendido COM O SEU CÓDIGO: cliente que comprou por outro vendedor aparece aqui como inativo.',
        '',
        `Não confunda com os "Inativos" do card Carteira por Segmento: lá são filiais e o corte é 365 dias sobre a última compra registrada no cadastro; aqui são empresas e o corte é ter ou não nota sua nos últimos ${janelaMeses} meses.`,
    ].join('\n');
});
</script>

<template>
    <DarkCard title="Potencial da Carteira" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M12 3v18" stroke-linecap="round" />
                <path d="M7 8h7a2.5 2.5 0 0 1 0 5H7h8a2.5 2.5 0 0 1 0 5H7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>

        <template #actions>
            <!-- `title` nativo: o projeto não tem componente de tooltip, e é o mesmo padrão
                 dos botões de ação das tabelas, onde o title faz o papel de rótulo. -->
            <span
                class="inline-flex h-5 w-5 cursor-help items-center justify-center rounded-full border border-gray-500 text-[0.7rem] font-bold text-gray-300 transition hover:border-white hover:text-white"
                :title="explicacao"
            >i</span>
        </template>

        <div v-if="temCarteira" class="flex flex-col gap-3">
            <!--
                Enquanto os pesos por segmento forem todos 1, este card mede alcance, não
                prioridade. Sem o aviso, o vendedor leria a ordem dos painéis como
                recomendação da empresa — e hoje ela é só contagem bruta.
            -->
            <p
                v-if="potencialCarteira.pesosPadrao"
                class="rounded border border-amber/40 bg-amber/10 px-2 py-1 text-xs text-amber-dark"
            >
                Pesos por segmento ainda não definidos — as três famílias contam igual.
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="familia in familiasOrdenadas"
                    :key="familia.familia"
                    class="rounded border border-gray-200 bg-gray-50 p-3"
                >
                    <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">
                        {{ familia.rotulo }}
                    </p>

                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-3xl font-bold leading-none text-navy">{{ familia.potencial }}</span>
                        <span class="text-xs text-gray-500">
                            {{ familia.potencial === 1 ? 'cliente não compra' : 'clientes não compram' }}
                        </span>
                    </p>

                    <div
                        class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200"
                        :title="`${familia.compram} de ${familia.candidatos} clientes já compram`"
                    >
                        <div class="h-full bg-teal" :style="{ width: familia.cobertura + '%' }" />
                    </div>

                    <!--
                        ⚠️ O sufixo "12m" no rótulo não é enfeite: o card Carteira por
                        Segmento, na mesma tela, também tem um número chamado "Inativos" —
                        e é OUTRO (188 contra 132 no mesmo vendedor). Lá são filiais com
                        365 dias sem compra no cadastro; aqui são empresas sem nota DESTE
                        vendedor na janela. Dois números com o mesmo nome lado a lado é
                        como o usuário deixa de confiar na tela inteira.

                        ⚠️ Dois BOTÕES de largura inteira, não dois textos pequenos com
                        link. A primeira versão punha os números numa linha de 11px, com
                        alvo de 60×21px: o link funcionava e mesmo assim ninguém acertava
                        nele — e como o painel inteiro tinha sido clicável antes, quem
                        clicava no número grande achava que estava quebrado. Alvo pequeno
                        é indistinguível de link quebrado para quem usa.
                    -->
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <Link
                            :href="hrefAtivos(familia.familia)"
                            class="flex flex-col items-center rounded border border-gray-300 bg-white px-2 py-1.5 transition hover:border-navy hover:bg-navy/5"
                            title="Compram de você, mas não esta família. Abre a Carteira já recortada."
                        >
                            <span class="text-sm font-bold leading-none text-navy">{{ familia.potencialAtivos }}</span>
                            <span class="mt-0.5 text-[0.6rem] uppercase tracking-wide text-gray-500">ativos {{ potencialCarteira.janelaMeses }}m</span>
                        </Link>
                        <Link
                            :href="hrefInativos"
                            class="flex flex-col items-center rounded border border-gray-300 bg-white px-2 py-1.5 transition hover:border-navy hover:bg-navy/5"
                            title="Sem compra nenhuma no período. Abre os inativos da Carteira."
                        >
                            <span class="text-sm font-bold leading-none text-gray-600">{{ familia.potencialInativos }}</span>
                            <span class="mt-0.5 text-[0.6rem] uppercase tracking-wide text-gray-500">inativos {{ potencialCarteira.janelaMeses }}m</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-gray-400">Nenhum cliente nesta carteira.</p>
    </DarkCard>
</template>
