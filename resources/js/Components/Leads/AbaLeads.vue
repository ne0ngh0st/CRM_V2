<script setup>
/**
 * O miolo da aba Leads da Carteira: tabela, paginação, Excel e os modais das ações de
 * linha (observação, agendamento, cartão CNPJ e a ficha do que chegou pelo site).
 *
 * Até 2026-09-29 isto era a página `/leads`. Os leads passaram a morar na Carteira (abas
 * Clientes · Leads · Funil · Calendário, uma busca só); a página cuida do que é comum às
 * abas, e aqui fica só o que é da lista de leads.
 *
 * ⚠️ `filtros` é o objeto REACTIVE da página, recebido por referência para o Excel sair
 * igual à tela. Este componente não o altera.
 */
import { reactive, ref } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import Pagination from '@/Components/Pagination.vue';
import LeadsTabela from '@/Components/Leads/LeadsTabela.vue';
import AgendarLigacaoLeadModal from '@/Components/Leads/AgendarLigacaoLeadModal.vue';
import CapturaWordpressDetalhe from '@/Components/Leads/CapturaWordpressDetalhe.vue';
import ObservacoesModal from '@/Components/Observacoes/ObservacoesModal.vue';
import CartaoCnpjModal from '@/Components/Receita/CartaoCnpjModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';

defineProps({
    leads: { type: Object, required: true },
    filtros: { type: Object, required: true },
    temFiltrosAtivos: { type: Boolean, default: false },
    podeAgir: { type: Boolean, default: false },
});

/*
 * ⚠️ Chave de texto, não uma ref por modal: no template a ref chega desembrulhada, e
 * passá-la para `abrir()` mexeria numa cópia (o modal não abriria, sem erro).
 */
const modal = reactive({ observacao: false, agendamento: false, cartaoCnpj: false, captura: false });
const leadAtivo = ref(null);
const capturaJson = ref(null);
const capturaErro = ref('');

function abrir(qual, lead) {
    leadAtivo.value = lead;
    modal[qual] = true;
}

async function abrirCaptura(lead) {
    abrir('captura', lead);
    capturaJson.value = null;
    capturaErro.value = '';
    try {
        const res = await fetch(route('leads.captura', lead.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (! res.ok) throw new Error(`HTTP ${res.status}`);
        capturaJson.value = await res.json();
    } catch {
        capturaErro.value = 'Não foi possível carregar os dados desta captura.';
    }
}
</script>

<template>
    <DarkCard title="Leads" :subtitle="`${leads.total} lead${leads.total !== 1 ? 's' : ''}`">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <circle cx="9" cy="7" r="3" />
                <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                <path d="M19 8v6M16 11h6" stroke-linecap="round" />
            </svg>
        </template>
        <template #actions>
            <ExportarExcelButton rota="leads.exportar" :filtros="filtros" :tem-filtros-ativos="temFiltrosAtivos" />
        </template>

        <LeadsTabela
            v-if="leads.data.length"
            :leads="leads.data"
            :pode-ligar="podeAgir"
            :pode-agendar="podeAgir"
            :pode-orcamento="podeAgir"
            :pode-observar="true"
            :pode-excluir="true"
            @observacao="(l) => abrir('observacao', l)"
            @agendar-ligacao="(l) => abrir('agendamento', l)"
            @cartao-cnpj="(l) => abrir('cartaoCnpj', l)"
            @captura="abrirCaptura"
        />
        <p v-else class="text-sm text-gray-400">Nenhum lead encontrado com os filtros atuais.</p>

        <div class="mt-4">
            <Pagination :meta="leads" :only="['leads']" />
        </div>
    </DarkCard>

    <ObservacoesModal
        :show="modal.observacao"
        :subtitulo="leadAtivo?.razaoSocial || leadAtivo?.nome || ''"
        :historico-url="leadAtivo ? route('observacoes.porLead', leadAtivo.id) : null"
        :payload="leadAtivo ? { lead_id: leadAtivo.id, cnpj: leadAtivo.cnpj || undefined } : {}"
        @close="modal.observacao = false"
    />
    <CartaoCnpjModal
        :show="modal.cartaoCnpj"
        :cliente="leadAtivo ? { razaoSocial: leadAtivo.razaoSocial || leadAtivo.nome, cnpj: leadAtivo.cnpj } : null"
        :url="leadAtivo ? route('leads.cartaoCnpj', leadAtivo.id) : ''"
        @close="modal.cartaoCnpj = false"
    />
    <AgendarLigacaoLeadModal :show="modal.agendamento" :lead="leadAtivo" @close="modal.agendamento = false" />
    <ModalPadrao
        :show="modal.captura"
        titulo="Dados recebidos do site"
        :subtitulo="leadAtivo?.razaoSocial || leadAtivo?.nome || ''"
        max-width="2xl"
        @close="modal.captura = false"
    >
        <p v-if="capturaErro" class="text-sm text-red-600">{{ capturaErro }}</p>
        <p v-else-if="! capturaJson" class="text-sm text-gray-400">Carregando…</p>
        <CapturaWordpressDetalhe v-else :captura="capturaJson" />
    </ModalPadrao>
</template>
