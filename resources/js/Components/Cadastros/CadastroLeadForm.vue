<script setup>
import { useForm } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const form = useForm({
    razao_social: '',
    nome_fantasia: '',
    cnpj: '',
    email: '',
    telefone: '',
    endereco: '',
});

function submit() {
    form.post(route('cadastros.leads.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <DarkCard title="Lead manual" subtitle="Registro rápido na base comercial — sem e-mail automático">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" stroke-linecap="round" />
                <circle cx="9" cy="7" r="3" />
                <path d="M19 11h2M20 10v2" stroke-linecap="round" />
            </svg>
        </template>

        <p class="mb-4 text-xs text-gray-500">Vai para a lista de leads manuais da sua carteira.</p>

        <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <div>
                <InputLabel value="Razão social *" class="!text-xs" />
                <TextInput v-model="form.razao_social" class="mt-1 block w-full text-xs" required />
                <InputError :message="form.errors.razao_social" />
            </div>
            <div>
                <InputLabel value="Nome fantasia" class="!text-xs" />
                <TextInput v-model="form.nome_fantasia" class="mt-1 block w-full text-xs" />
            </div>
            <div>
                <InputLabel value="CNPJ" class="!text-xs" />
                <TextInput v-model="form.cnpj" class="mt-1 block w-full text-xs" />
            </div>
            <div>
                <InputLabel value="Telefone *" class="!text-xs" />
                <TextInput v-model="form.telefone" class="mt-1 block w-full text-xs" required />
                <InputError :message="form.errors.telefone" />
            </div>
            <div>
                <InputLabel value="E-mail *" class="!text-xs" />
                <TextInput v-model="form.email" type="email" class="mt-1 block w-full text-xs" required />
                <InputError :message="form.errors.email" />
            </div>
            <div>
                <InputLabel value="Endereço *" class="!text-xs" />
                <TextInput v-model="form.endereco" class="mt-1 block w-full text-xs" required />
                <InputError :message="form.errors.endereco" />
            </div>
            <div class="sm:col-span-2 flex justify-end">
                <PrimaryButton :disabled="form.processing">Salvar lead</PrimaryButton>
            </div>
        </form>
    </DarkCard>
</template>
