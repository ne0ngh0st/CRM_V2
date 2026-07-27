<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import UpdateFotoPerfilForm from './Partials/UpdateFotoPerfilForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    perfil: {
        type: Object,
        required: true,
    },
    status: {
        type: String,
        default: null,
    },
});

const roleLabel = computed(() => ROTULOS_PERFIL[props.perfil.role] || props.perfil.role || '—');

const flashMessage = computed(() => {
    const map = {
        'dados-atualizados': 'Dados pessoais atualizados com sucesso.',
        'foto-atualizada': 'Foto de perfil atualizada com sucesso.',
        'foto-removida': 'Foto de perfil removida com sucesso.',
        'senha-atualizada': 'Senha alterada com sucesso.',
    };
    return map[props.status] || null;
});

const infoRows = computed(() => {
    const rows = [
        { label: 'Nome', value: props.perfil.nome || '—' },
        { label: 'E-mail', value: props.perfil.email || '—' },
        { label: 'Telefone', value: props.perfil.telefone || '—' },
        { label: 'Perfil', value: roleLabel.value },
    ];
    if (props.perfil.cod_vendedor) {
        rows.push({ label: 'Código vendedor', value: props.perfil.cod_vendedor });
    }
    return rows;
});
</script>

<template>
    <Head title="Meu Perfil" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto w-full max-w-[960px] px-3 sm:px-4 lg:px-6">
                <PageHero title="Meu Perfil">
                    <template #icon>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                            />
                        </svg>
                    </template>
                    <template #subtitle>
                        Gerencie seus dados e configurações da conta
                    </template>
                </PageHero>

                <div
                    v-if="flashMessage"
                    class="mb-4 rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800"
                    role="status"
                >
                    {{ flashMessage }}
                </div>

                <div class="space-y-4">
                    <DarkCard title="Informações do Usuário" subtitle="Dados da conta (somente leitura)">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"
                                />
                            </svg>
                        </template>

                        <dl class="divide-y divide-gray-200 border border-gray-200">
                            <div
                                v-for="row in infoRows"
                                :key="row.label"
                                class="grid grid-cols-1 gap-1 px-3 py-2.5 sm:grid-cols-3 sm:gap-4"
                            >
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ row.label }}
                                </dt>
                                <dd class="text-sm text-gray-900 sm:col-span-2">
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                    </DarkCard>

                    <DarkCard title="Foto de Perfil" subtitle="JPG, PNG ou GIF — máx. 5MB">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                                />
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"
                                />
                            </svg>
                        </template>

                        <UpdateFotoPerfilForm :foto-url="perfil.foto_url" />
                    </DarkCard>

                    <DarkCard title="Alterar Dados Pessoais" subtitle="Nome de exibição, e-mail e telefone">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                />
                            </svg>
                        </template>

                        <UpdateProfileInformationForm />
                    </DarkCard>

                    <DarkCard title="Alterar Senha" subtitle="Mínimo de 6 caracteres">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                                />
                            </svg>
                        </template>

                        <UpdatePasswordForm />
                    </DarkCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
