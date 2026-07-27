<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    fotoUrl: { type: String, default: null },
});

const fileInput = ref(null);
const previewUrl = ref(null);
const clientError = ref('');

const form = useForm({
    foto_perfil: null,
});

const exibicao = computed(() => previewUrl.value || props.fotoUrl);

function escolherFoto() {
    fileInput.value?.click();
}

function onFileChange(event) {
    clientError.value = '';
    const file = event.target.files?.[0];
    if (!file) return;

    const tipos = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!tipos.includes(file.type)) {
        clientError.value = 'Formatos aceitos: JPG, PNG ou GIF.';
        event.target.value = '';
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        clientError.value = 'A foto deve ter no máximo 5MB.';
        event.target.value = '';
        return;
    }

    form.foto_perfil = file;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(file);
}

function salvarFoto() {
    if (!form.foto_perfil) return;

    form.post(route('profile.foto.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (previewUrl.value) {
                URL.revokeObjectURL(previewUrl.value);
                previewUrl.value = null;
            }
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

function removerFoto() {
    if (!confirm('Tem certeza que deseja remover sua foto de perfil?')) return;

    router.delete(route('profile.foto.destroy'), { preserveScroll: true });
}

const user = usePage().props.auth.user;
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        <div
            class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-gray-200 bg-gray-100"
        >
            <img
                v-if="exibicao"
                :src="exibicao"
                alt="Foto de perfil"
                class="h-full w-full object-cover"
            />
            <svg
                v-else
                class="h-12 w-12 text-gray-400"
                fill="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path
                    d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v1.2h19.2v-1.2c0-3.2-6.4-4.8-9.6-4.8z"
                />
            </svg>
        </div>

        <div class="min-w-0 flex-1">
            <h4 class="text-sm font-semibold text-gray-900">Minha Foto de Perfil</h4>
            <p class="mt-1 text-sm text-gray-600">
                Personalize sua conta com uma foto. Formatos aceitos: JPG, PNG, GIF. Tamanho máximo: 5MB.
            </p>

            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/jpg,image/png,image/gif"
                class="hidden"
                @change="onFileChange"
            />

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded border border-gray-200 bg-white px-3 text-xs font-semibold uppercase tracking-wide text-gray-700 hover:bg-gray-50"
                    @click="escolherFoto"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Escolher Foto
                </button>

                <PrimaryButton
                    v-if="form.foto_perfil"
                    type="button"
                    :disabled="form.processing"
                    @click="salvarFoto"
                >
                    Salvar Foto
                </PrimaryButton>

                <button
                    v-if="user.foto_url && !form.foto_perfil"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded border border-red-200 bg-white px-3 text-xs font-semibold uppercase tracking-wide text-red-700 hover:bg-red-50"
                    @click="removerFoto"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Remover Foto
                </button>
            </div>

            <InputError class="mt-2" :message="clientError || form.errors.foto_perfil" />
            <Transition
                enter-active-class="transition ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition ease-in-out"
                leave-to-class="opacity-0"
            >
                <p v-if="form.recentlySuccessful" class="mt-2 text-sm text-teal">
                    Foto atualizada.
                </p>
            </Transition>
        </div>
    </div>
</template>
