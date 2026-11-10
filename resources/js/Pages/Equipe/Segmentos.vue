<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import CadastroSegmentos from '@/Components/Equipe/CadastroSegmentos.vue';
import QuadroSegmentos from '@/Components/Equipe/QuadroSegmentos.vue';

defineProps({
    role: String,
    podeGerenciar: Boolean,
    podeDefinirEspecialista: Boolean,
    quadro: Object,
    cadastro: Array,
});

const aba = ref('quadro');

function classeAba(destaque) {
    return [
        'rounded border px-3 py-1.5 text-sm font-medium',
        destaque
            ? 'border-teal bg-teal text-white'
            : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50',
    ];
}
</script>

<template>
    <Head title="Segmentos" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Segmentos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3.5" y="3.5" width="7" height="7" rx="1" />
                            <rect x="13.5" y="3.5" width="7" height="7" rx="1" />
                            <rect x="3.5" y="13.5" width="7" height="7" rx="1" />
                            <rect x="13.5" y="13.5" width="7" height="7" rx="1" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Divisão dos segmentos de mercado por supervisor e vendedor, nas equipes internas.
                        <template v-if="podeDefinirEspecialista">A estrela marca o especialista do segmento.</template>
                    </template>
                </PageHero>

                <div v-if="podeGerenciar" class="flex flex-wrap gap-2">
                    <button type="button" :class="classeAba(aba === 'quadro')" @click="aba = 'quadro'">Quem atende</button>
                    <button type="button" :class="classeAba(aba === 'cadastro')" @click="aba = 'cadastro'">Cadastro</button>
                </div>

                <CadastroSegmentos v-if="podeGerenciar && aba === 'cadastro'" :segmentos="cadastro" />
                <QuadroSegmentos
                    v-else
                    :quadro="quadro"
                    :pode-editar="true"
                    :pode-definir-especialista="podeDefinirEspecialista"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
