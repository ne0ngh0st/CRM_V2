<script setup>
/**
 * A folha de orçamento na tela — o mesmo documento que o cliente recebe, só que
 * editável. O vendedor preenche o que vai sair impresso, na posição em que vai sair.
 *
 * ⚠️ Este arquivo tem um par: resources/views/orcamentos/pdf.blade.php.
 * Ordem de seção, rótulo, cor e proporção têm que bater nos dois. Se divergirem,
 * a promessa da tela ("é o PDF") deixa de ser verdade.
 *
 * ⚠️ O que NÃO entra aqui, porque não entra no PDF do cliente: status do gestor,
 * nível de aprovação exigido, quem aprovou e motivo de rejeição. Isso é bastidor
 * interno e vive no cabeçalho da página (Form.vue), fora da folha.
 */
import DocSecao from '@/Components/Orcamentos/DocSecao.vue';
import { formatBRL } from '@/utils/formato.js';

const props = defineProps({
    orcamentoId: { type: [Number, String], default: null },
    emitidoEm: { type: String, default: '' },
    vendedor: { type: Object, default: () => ({}) },
    tipoProdutoServico: { type: String, default: 'produto' },
    resumo: {
        type: Object,
        default: () => ({
            subtotalProdutosSemIpi: 0,
            subtotalProdutosComIpi: 0,
            subtotalEtiquetas: 0,
            totalGeral: 0,
        }),
    },
});

// Mesmo tratamento do PDF: número curto fica pobre num documento comercial.
const numeroFormatado = () => (props.orcamentoId ? String(props.orcamentoId).padStart(4, '0') : '—');
</script>

<template>
    <article class="mx-auto w-full max-w-[1180px] border border-gray-300 bg-white px-8 py-7 font-doc shadow-sm">
        <header class="flex items-start justify-between gap-6">
            <img src="/images/autopel-logo.png" alt="Autopel Soluções" class="h-11 w-auto shrink-0" />
            <div class="text-right">
                <p class="text-[0.95rem] font-bold leading-tight text-navy">Autopel Soluções</p>
                <p class="text-[0.7rem] text-gray-500">CNPJ 06.698.091/0005-90</p>
            </div>
        </header>

        <div class="mt-4 flex items-center justify-between bg-navy px-4 py-2.5">
            <span class="text-[1.05rem] font-bold tracking-[0.16em] text-white">ORÇAMENTO</span>
            <span class="text-[0.9rem] font-semibold text-white">Nº {{ numeroFormatado() }}</span>
        </div>
        <div class="h-[3px] bg-cyan" />

        <!-- Só existe na tela: é a ferramenta de preenchimento, não conteúdo do documento. -->
        <div v-if="$slots['cliente-busca']" class="mt-4 border border-dashed border-gray-300 bg-gray-50 px-3 py-2.5">
            <slot name="cliente-busca" />
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <slot name="info-cliente" />
            <slot name="info-proposta" />
        </div>

        <DocSecao titulo="Itens do orçamento" :com-caixa="false">
            <slot name="itens" />
        </DocSecao>

        <div class="mt-3 flex justify-end">
            <table class="w-full max-w-[380px] border border-gray-200">
                <tbody>
                    <tr>
                        <td class="px-3 py-1.5 text-[0.78rem] text-gray-500">Subtotal s/ IPI</td>
                        <td class="px-3 py-1.5 text-right text-[0.82rem] font-medium">{{ formatBRL(resumo.subtotalProdutosSemIpi) }}</td>
                    </tr>
                    <tr v-if="tipoProdutoServico === 'produto'">
                        <td class="px-3 py-1.5 text-[0.78rem] text-gray-500">Subtotal c/ IPI</td>
                        <td class="px-3 py-1.5 text-right text-[0.82rem] font-medium">{{ formatBRL(resumo.subtotalProdutosComIpi) }}</td>
                    </tr>
                    <tr v-if="resumo.subtotalEtiquetas > 0">
                        <td class="px-3 py-1.5 text-[0.78rem] text-gray-500">Subtotal etiquetas</td>
                        <td class="px-3 py-1.5 text-right text-[0.82rem] font-medium">{{ formatBRL(resumo.subtotalEtiquetas) }}</td>
                    </tr>
                    <tr class="bg-navy text-white">
                        <td class="px-3 py-2.5 text-[0.9rem] font-bold tracking-wide">VALOR TOTAL</td>
                        <td class="px-3 py-2.5 text-right text-[0.95rem] font-bold">{{ formatBRL(resumo.totalGeral) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <DocSecao titulo="Observações">
            <slot name="observacoes" />
        </DocSecao>

        <DocSecao titulo="Condições de fornecimento">
            <slot name="condicoes" />
        </DocSecao>

        <DocSecao titulo="Aceite do cliente">
            <div class="grid grid-cols-1 gap-6 pt-1 sm:grid-cols-[46%_20%_1fr]">
                <div>
                    <div class="h-7" />
                    <p class="border-t border-gray-400 pt-1 text-[0.7rem] text-gray-500">Nome do responsável</p>
                </div>
                <div>
                    <div class="h-7" />
                    <p class="border-t border-gray-400 pt-1 text-[0.7rem] text-gray-500">Data</p>
                </div>
                <div>
                    <div class="h-7" />
                    <p class="border-t border-gray-400 pt-1 text-[0.7rem] text-gray-500">Assinatura e carimbo</p>
                </div>
            </div>
        </DocSecao>

        <footer class="mt-5 border-t border-gray-200 pt-2 text-[0.68rem] text-gray-400">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>Autopel Soluções · CNPJ 06.698.091/0005-90 · Orçamento nº {{ numeroFormatado() }}</span>
                <span v-if="vendedor?.nome">Vendedor: {{ vendedor.nome }}</span>
            </div>
            <p class="mt-0.5">Documento gerado pelo sistema PALMA em {{ emitidoEm }} — sem valor fiscal.</p>
        </footer>
    </article>
</template>
