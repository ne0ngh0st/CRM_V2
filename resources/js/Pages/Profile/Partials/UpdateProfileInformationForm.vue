<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';

const user = usePage().props.auth.user;

const form = useForm({
    display_name: user.display_name || user.name || '',
    email: user.email || '',
    telefone: user.telefone || '',
});

function mascaraTelefone(event) {
    let digits = event.target.value.replace(/\D/g, '').slice(0, 11);
    if (digits.length > 6) {
        form.telefone = `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
    } else if (digits.length > 2) {
        form.telefone = `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
    } else if (digits.length > 0) {
        form.telefone = `(${digits}`;
    } else {
        form.telefone = '';
    }
}

function submit() {
    form.patch(route('profile.update'), { preserveScroll: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div>
            <InputLabel for="display_name" value="Nome" class="!text-xs" />
            <TextInput
                id="display_name"
                v-model="form.display_name"
                type="text"
                class="mt-1 block w-full"
                required
                autocomplete="name"
            />
            <InputError class="mt-1" :message="form.errors.display_name" />
        </div>

        <div>
            <InputLabel for="email" value="E-mail" class="!text-xs" />
            <TextInput
                id="email"
                v-model="form.email"
                type="email"
                class="mt-1 block w-full"
                required
                autocomplete="username"
            />
            <InputError class="mt-1" :message="form.errors.email" />
        </div>

        <div>
            <InputLabel for="telefone" value="Telefone" class="!text-xs" />
            <TextInput
                id="telefone"
                v-model="form.telefone"
                type="tel"
                class="mt-1 block w-full"
                placeholder="(11) 99999-9999"
                autocomplete="tel"
                @input="mascaraTelefone"
            />
            <p class="mt-1 text-xs text-gray-500">Formato: (11) 99999-9999</p>
            <InputError class="mt-1" :message="form.errors.telefone" />
        </div>

        <div class="flex items-center gap-3 pt-1">
            <PrimaryButton :disabled="form.processing">Atualizar Dados</PrimaryButton>
            <Transition
                enter-active-class="transition ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition ease-in-out"
                leave-to-class="opacity-0"
            >
                <p v-if="form.recentlySuccessful" class="text-sm text-teal">
                    Dados atualizados.
                </p>
            </Transition>
        </div>
    </form>
</template>
