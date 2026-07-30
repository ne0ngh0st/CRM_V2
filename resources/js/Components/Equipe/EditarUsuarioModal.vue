<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    show: { type: Boolean, default: false },
    usuario: { type: Object, default: null },
    opcoes: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    name: '',
    display_name: '',
    email: '',
    perfil: '',
    cod_vendedor: '',
    cod_super: '',
    estado: '',
    tipo_usuario: '',
    segmentos: [],
});

watch(
    () => props.usuario,
    (usuario) => {
        if (!usuario) return;
        form.defaults({
            name: usuario.nomeCompleto,
            display_name: usuario.nomeExibicao || '',
            email: usuario.email,
            perfil: usuario.perfil,
            cod_vendedor: usuario.codVendedor || '',
            cod_super: usuario.codSuper || '',
            estado: usuario.estado || '',
            tipo_usuario: usuario.tipoUsuario || '',
            segmentos: usuario.segmentosIds ? [...usuario.segmentosIds] : [],
        }).reset();
    },
    { immediate: true },
);

function fechar() {
    form.clearErrors();
    emit('close');
}

function salvar() {
    form.patch(route('equipe.update', props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="fechar">
        <form v-if="usuario" class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Editar Usuário</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="edit_name" value="Nome Completo *" />
                    <TextInput id="edit_name" v-model="form.name" class="mt-1 block w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="edit_display_name" value="Nome de Exibição" />
                    <TextInput id="edit_display_name" v-model="form.display_name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.display_name" class="mt-1" />
                </div>
            </div>

            <div class="mt-4">
                <InputLabel for="edit_email" value="E-mail *" />
                <TextInput id="edit_email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                <InputError :message="form.errors.email" class="mt-1" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="edit_perfil" value="Perfil *" />
                    <select id="edit_perfil" v-model="form.perfil" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan" required>
                        <option v-for="p in opcoes.perfis" :key="p" :value="p">{{ ROTULOS_PERFIL[p] || p }}</option>
                    </select>
                    <InputError :message="form.errors.perfil" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="edit_cod_vendedor" value="Código Vendedor" />
                    <TextInput id="edit_cod_vendedor" v-model="form.cod_vendedor" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cod_vendedor" class="mt-1" />
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel for="edit_cod_super" value="Supervisor" />
                    <select id="edit_cod_super" v-model="form.cod_super" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan">
                        <option value="">Sem supervisor</option>
                        <option v-for="s in opcoes.supervisores" :key="s.codVendedor" :value="s.codVendedor">{{ s.nome }}</option>
                    </select>
                    <InputError :message="form.errors.cod_super" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="edit_estado" value="Estado" />
                    <TextInput id="edit_estado" v-model="form.estado" maxlength="2" class="mt-1 block w-full uppercase" placeholder="SP" />
                    <InputError :message="form.errors.estado" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="edit_tipo_usuario" value="Tipo de Usuário" />
                    <select id="edit_tipo_usuario" v-model="form.tipo_usuario" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan">
                        <option value="">Selecione</option>
                        <option value="INTERNO">Interno</option>
                        <option value="EXTERNO">Externo</option>
                    </select>
                    <InputError :message="form.errors.tipo_usuario" class="mt-1" />
                </div>
            </div>

            <div v-if="form.cod_vendedor" class="mt-4">
                <InputLabel value="Segmentos" />
                <div class="mt-1 grid max-h-28 grid-cols-2 gap-x-3 gap-y-1 overflow-y-auto rounded-md border border-gray-300 p-2 sm:grid-cols-3">
                    <label v-for="s in opcoes.segmentos" :key="s.id" class="flex items-center gap-1.5 text-xs text-gray-600">
                        <input
                            type="checkbox"
                            :value="s.id"
                            v-model="form.segmentos"
                            class="rounded border-gray-300 text-teal focus:ring-cyan"
                        />
                        {{ s.nome }}
                    </label>
                </div>
                <InputError :message="form.errors.segmentos" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
