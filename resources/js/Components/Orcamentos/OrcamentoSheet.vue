<script setup>
defineProps({
    orcamentoId: { type: [Number, String], default: null },
    emitidoEm: { type: String, default: '' },
    vendedor: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <div class="h-1 bg-gradient-to-r from-navy via-teal to-cyan" />

        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 px-6 py-5">
            <div class="flex items-start gap-3">
                <img src="/images/autopel-logo.png" alt="Autopel" class="h-12 w-auto shrink-0" />
                <div class="text-xs leading-snug text-gray-600">
                    <p class="text-sm font-bold text-navy">Autopel Soluções</p>
                    <p>CNPJ 06.698.091/0005-90</p>
                    <p v-if="vendedor?.nome">{{ vendedor.nome }}<span v-if="vendedor.codigo"> · Cód. {{ vendedor.codigo }}</span></p>
                    <p v-if="vendedor?.telefone || vendedor?.email">
                        {{ vendedor.telefone }}<span v-if="vendedor.telefone && vendedor.email"> · </span>{{ vendedor.email }}
                    </p>
                </div>
            </div>

            <div class="rounded border border-cyan/30 bg-cyan/5 px-4 py-2.5 text-right">
                <p class="text-sm font-bold uppercase tracking-wide text-navy">Orçamento</p>
                <p class="text-xs text-gray-500">Nº {{ orcamentoId ?? '—' }}</p>
                <p class="text-xs text-gray-500">Emitido em {{ emitidoEm }}</p>
            </div>
        </div>

        <div v-if="$slots['cliente-busca']" class="border-b border-dashed border-gray-300 bg-gray-50 px-6 py-3">
            <slot name="cliente-busca" />
        </div>

        <div class="grid gap-4 border-b border-gray-200 px-6 py-5 sm:grid-cols-2">
            <div>
                <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Dados do Cliente</h3>
                <slot name="info-cliente" />
            </div>
            <div>
                <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Informações do Orçamento</h3>
                <slot name="info-orcamento" />
            </div>
        </div>

        <div class="border-b border-gray-200 px-6 py-5">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Produto/Serviço</h3>
            <slot name="itens" />
        </div>

        <div class="border-b border-gray-200 px-6 py-5">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Observações</h3>
            <slot name="observacoes" />
        </div>

        <div class="border-b border-gray-200 px-6 py-5">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Outras Informações</h3>
            <slot name="outras-informacoes" />
        </div>

        <div class="grid gap-4 px-6 py-4 text-xs text-gray-500 sm:grid-cols-3">
            <div>
                <p class="font-semibold uppercase tracking-wide text-gray-400">Vendedor Responsável</p>
                <p class="mt-0.5 text-gray-700">{{ vendedor?.nome ?? '—' }}</p>
            </div>
            <div>
                <p class="font-semibold uppercase tracking-wide text-gray-400">Condições</p>
                <p class="mt-0.5 text-gray-700">Prazo de entrega: a combinar</p>
            </div>
            <div>
                <p class="font-semibold uppercase tracking-wide text-gray-400">Autopel</p>
                <p class="mt-0.5 text-gray-700">Autopel Soluções · CNPJ 06.698.091/0005-90</p>
            </div>
        </div>
    </div>
</template>
