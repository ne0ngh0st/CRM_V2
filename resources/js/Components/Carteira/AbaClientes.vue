<script setup>
/**
 * O miolo da aba Clientes da Carteira: faixas de recorte, tabela, paginação, Excel e os
 * modais das ações de linha.
 *
 * Saiu do Carteira/Index.vue em 2026-09-29, quando os leads passaram a morar na mesma
 * página (abas Clientes · Leads · Funil · Calendário). A página cuida do que é comum às
 * abas — busca, visão, filtros e a troca de aba —; aqui fica só o que é da lista de
 * clientes.
 *
 * ⚠️ `filtros` é o objeto REACTIVE da página, recebido por referência: o botão de Excel
 * precisa dele exatamente como está na tela. Este componente NUNCA o altera — pede à
 * página pelos eventos.
 */
import { reactive, ref } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import Pagination from '@/Components/Pagination.vue';
import CarteiraTabela from '@/Components/Carteira/CarteiraTabela.vue';
import MotivoInatividadeModal from '@/Components/Carteira/MotivoInatividadeModal.vue';
import AgendarLigacaoModal from '@/Components/Carteira/AgendarLigacaoModal.vue';
import ObservacoesModal from '@/Components/Observacoes/ObservacoesModal.vue';
import CartaoCnpjModal from '@/Components/Receita/CartaoCnpjModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';

defineProps({
    clientes: { type: Object, required: true },
    filtros: { type: Object, required: true },
    temFiltrosAtivos: { type: Boolean, default: false },
    podeOperar: { type: Boolean, default: false },
    listaAgrupada: { type: Boolean, default: true },
    subtitulo: { type: String, default: '' },
    // Recortes que chegam de fora da tela (Painel e Visão Diretor), sem campo na barra.
    semFamiliaRotulo: { type: String, default: null },
    semFamiliaEmpresas: { type: Number, default: null },
    contaAlvo: { type: Object, default: null },
});

defineEmits(['ordenar', 'limpar-sem-familia', 'limpar-conta-alvo']);

/*
 * ⚠️ Objeto reativo com chave de texto, e não uma ref por modal passada para `abrir()`:
 * no template a ref chega DESEMBRULHADA (o booleano), e `abrir(modalMotivo, c)` mexeria
 * numa cópia — o modal simplesmente não abriria, sem erro.
 */
const modal = reactive({ motivo: false, observacao: false, agendamento: false, cartaoCnpj: false });
const clienteAtivo = ref(null);

function abrir(qual, cliente) {
    clienteAtivo.value = cliente;
    modal[qual] = true;
}
</script>

<template>
    <!--
        Recorte vindo do card de Potencial da Carteira do Painel. Precisa ser anunciado:
        sem isso a pessoa chega numa lista bem menor que a carteira dela, sem campo na
        barra de filtros explicando o porquê, e conclui que a tela quebrou.
    -->
    <div
        v-if="filtros.sem_familia"
        class="flex flex-wrap items-center justify-between gap-2 rounded border border-cyan/40 bg-cyan/10 px-3 py-2"
    >
        <p class="text-sm text-gray-700">
            Mostrando apenas clientes que compraram nos últimos 12 meses e
            <strong class="font-semibold">ainda não compram {{ semFamiliaRotulo }}</strong>.
            <!-- Só no modo por filial: agrupada, a tabela lista as mesmas N empresas do
                 card do Painel e não há nada a reconciliar. -->
            <span v-if="! listaAgrupada && semFamiliaEmpresas" class="text-gray-500">
                São {{ semFamiliaEmpresas }}
                {{ semFamiliaEmpresas === 1 ? 'empresa' : 'empresas' }},
                listadas abaixo por filial.
            </span>
        </p>
        <button
            type="button"
            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
            @click="$emit('limpar-sem-familia')"
        >
            Limpar recorte
        </button>
    </div>

    <!-- Recorte vindo da Visão Diretor (clientes de uma conta-alvo). Mesmo motivo da
         faixa de cima. -->
    <div
        v-if="filtros.conta_alvo && contaAlvo"
        class="flex flex-wrap items-center justify-between gap-2 rounded border border-teal/40 bg-teal/10 px-3 py-2"
    >
        <p class="text-sm text-gray-700">
            Mostrando apenas os clientes da conta
            <strong class="font-semibold">{{ contaAlvo.nome }}</strong>
            (Visão Diretor → Maiores por segmento).
        </p>
        <button
            type="button"
            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
            @click="$emit('limpar-conta-alvo')"
        >
            Limpar recorte
        </button>
    </div>

    <DarkCard title="Carteira de Clientes" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
            </svg>
        </template>
        <template #actions>
            <!-- Quem decide entre baixar na hora e ir para a fila é o volume, no servidor. -->
            <ExportarExcelButton rota="carteira.exportar" :filtros="filtros" :tem-filtros-ativos="temFiltrosAtivos" />
        </template>

        <CarteiraTabela
            v-if="clientes.data.length"
            :clientes="clientes.data"
            :pode-ver-detalhes="true"
            :pode-ligar="podeOperar"
            :pode-agendar="podeOperar"
            :pode-orcamento="podeOperar"
            :pode-observar="true"
            :agrupado="listaAgrupada"
            :ordenar="filtros.ordenar"
            @ordenar="(v) => $emit('ordenar', v)"
            @motivo-inatividade="(c) => abrir('motivo', c)"
            @observacao="(c) => abrir('observacao', c)"
            @agendar-ligacao="(c) => abrir('agendamento', c)"
            @cartao-cnpj="(c) => abrir('cartaoCnpj', c)"
        />
        <p v-else class="text-sm text-gray-400">Nenhum cliente encontrado com os filtros atuais.</p>

        <div class="mt-4">
            <Pagination :meta="clientes" :only="['clientes']" />
        </div>
    </DarkCard>

    <MotivoInatividadeModal :show="modal.motivo" :cliente="clienteAtivo" @close="modal.motivo = false" />
    <ObservacoesModal
        :show="modal.observacao"
        :subtitulo="clienteAtivo ? `${clienteAtivo.razaoSocial} · ${clienteAtivo.cnpj || 'CNPJ não cadastrado'}` : ''"
        :historico-url="clienteAtivo ? route('observacoes.porCliente', clienteAtivo.id) : null"
        :payload="clienteAtivo ? { cliente_id: clienteAtivo.id, cnpj: clienteAtivo.cnpj || undefined } : {}"
        @close="modal.observacao = false"
    />
    <AgendarLigacaoModal :show="modal.agendamento" :cliente="clienteAtivo" @close="modal.agendamento = false" />
    <CartaoCnpjModal
        :show="modal.cartaoCnpj"
        :cliente="clienteAtivo"
        :url="clienteAtivo ? route('carteira.cartaoCnpj', clienteAtivo.id) : ''"
        @close="modal.cartaoCnpj = false"
    />
</template>
