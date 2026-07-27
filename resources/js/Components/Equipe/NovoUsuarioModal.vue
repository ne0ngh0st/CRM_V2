<script setup>
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
    opcoes: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    name: '',
    display_name: '',
    email: '',
    password: '',
    perfil: '',
    cod_vendedor: '',
    cod_super: '',
    estado: '',
    tipo_usuario: '',
});

function fechar() {
    form.reset();
    form.clearErrors();
    emit('close');
}

function criar() {
    form.post(route('equipe.store'), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="fechar">
        <form class="p-6" @submit.prevent="criar">
            <h2 class="text-lg font-semibold text-gray-800">Novo Usuário</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="novo_name" value="Nome Completo *" />
                    <TextInput id="novo_name" v-model="form.name" class="mt-1 block w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="novo_display_name" value="Nome de Exibição" />
                    <TextInput id="novo_display_name" v-model="form.display_name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.display_name" class="mt-1" />
                </div>
            </div>

            <div class="mt-4">
                <InputLabel for="novo_email" value="E-mail *" />
                <TextInput id="novo_email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                <InputError :message="form.errors.email" class="mt-1" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="novo_perfil" value="Perfil *" />
                    <select id="novo_perfil" v-model="form.perfil" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan" required>
                        <option value="" disabled>Selecione o perfil</option>
                        <option v-for="p in opcoes.perfis" :key="p" :value="p">{{ ROTULOS_PERFIL[p] || p }}</option>
                    </select>
                    <InputError :message="form.errors.perfil" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="novo_senha" value="Senha *" />
                    <TextInput id="novo_senha" v-model="form.password" type="password" class="mt-1 block w-full" required minlength="6" />
                    <InputError :message="form.errors.password" class="mt-1" />
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="novo_cod_vendedor" value="Código Vendedor" />
                    <TextInput id="novo_cod_vendedor" v-model="form.cod_vendedor" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cod_vendedor" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="novo_cod_super" value="Supervisor" />
                    <select id="novo_cod_super" v-model="form.cod_super" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan">
                        <option value="">Sem supervisor</option>
                        <option v-for="s in opcoes.supervisores" :key="s.codVendedor" :value="s.codVendedor">{{ s.nome }}</option>
                    </select>
                    <InputError :message="form.errors.cod_super" class="mt-1" />
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="novo_estado" value="Estado" />
                    <TextInput id="novo_estado" v-model="form.estado" maxlength="2" class="mt-1 block w-full uppercase" placeholder="SP" />
                    <InputError :message="form.errors.estado" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="novo_tipo_usuario" value="Tipo de Usuário" />
                    <select id="novo_tipo_usuario" v-model="form.tipo_usuario" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan">
                        <option value="">Selecione</option>
                        <option value="INTERNO">Interno</option>
                        <option value="EXTERNO">Externo</option>
                    </select>
                    <InputError :message="form.errors.tipo_usuario" class="mt-1" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">Criar Usuário</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
