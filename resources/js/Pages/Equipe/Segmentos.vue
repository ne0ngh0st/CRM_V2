<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import EquipeAbas from '@/Components/Equipe/EquipeAbas.vue';
import QuadroSegmentos from '@/Components/Equipe/QuadroSegmentos.vue';

defineProps({
    role: String,
    podeGerenciar: Boolean,
    quadro: Object,
});
</script>

<template>
    <Head title="Segmentos da equipe" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Segmentos da equipe">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3.5" y="3.5" width="7" height="7" rx="1" />
                            <rect x="13.5" y="3.5" width="7" height="7" rx="1" />
                            <rect x="3.5" y="13.5" width="7" height="7" rx="1" />
                            <rect x="13.5" y="13.5" width="7" height="7" rx="1" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Quem atende cada segmento — sem gráfico, só gente. Arraste para mudar.
                    </template>
                </PageHero>

                <EquipeAbas :ativa="'segmentos'" :pode-ver-organograma="podeGerenciar" />

                <QuadroSegmentos :quadro="quadro" :pode-editar="true" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
