<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const forca = computed(() => {
    const s = form.password || '';
    let score = 0;
    if (s.length >= 6) score += 1;
    if (s.length >= 10) score += 1;
    if (/[a-z]/.test(s) && /[A-Z]/.test(s)) score += 1;
    if (/\d/.test(s)) score += 1;
    if (/[^A-Za-z0-9]/.test(s)) score += 1;
    if (score <= 1) return { label: 'Fraca', tone: 'bg-red-500', width: '33%' };
    if (score <= 3) return { label: 'Média', tone: 'bg-amber-500', width: '66%' };
    return { label: 'Forte', tone: 'bg-green-500', width: '100%' };
});

function updatePassword() {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
}
</script>

<template>
    <form @submit.prevent="updatePassword" class="space-y-4">
        <div>
            <InputLabel for="current_password" value="Senha Atual" class="!text-xs" />
            <TextInput
                id="current_password"
                ref="currentPasswordInput"
                v-model="form.current_password"
                type="password"
                class="mt-1 block w-full"
                autocomplete="current-password"
                required
            />
            <InputError class="mt-1" :message="form.errors.current_password" />
        </div>

        <div>
            <InputLabel for="password" value="Nova Senha" class="!text-xs" />
            <TextInput
                id="password"
                ref="passwordInput"
                v-model="form.password"
                type="password"
                class="mt-1 block w-full"
                autocomplete="new-password"
                minlength="6"
                required
            />
            <div v-if="form.password" class="mt-2">
                <div class="h-1.5 w-full overflow-hidden rounded bg-gray-200">
                    <div
                        class="h-full transition-all duration-200"
                        :class="forca.tone"
                        :style="{ width: forca.width }"
                    />
                </div>
                <p class="mt-1 text-xs text-gray-500">Força: {{ forca.label }}</p>
            </div>
            <InputError class="mt-1" :message="form.errors.password" />
        </div>

        <div>
            <InputLabel for="password_confirmation" value="Confirmar Nova Senha" class="!text-xs" />
            <TextInput
                id="password_confirmation"
                v-model="form.password_confirmation"
                type="password"
                class="mt-1 block w-full"
                autocomplete="new-password"
                minlength="6"
                required
            />
            <InputError class="mt-1" :message="form.errors.password_confirmation" />
        </div>

        <div class="flex items-center gap-3 pt-1">
            <PrimaryButton :disabled="form.processing">Alterar Senha</PrimaryButton>
            <Transition
                enter-active-class="transition ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition ease-in-out"
                leave-to-class="opacity-0"
            >
                <p v-if="form.recentlySuccessful" class="text-sm text-teal">
                    Senha alterada.
                </p>
            </Transition>
        </div>
    </form>
</template>
