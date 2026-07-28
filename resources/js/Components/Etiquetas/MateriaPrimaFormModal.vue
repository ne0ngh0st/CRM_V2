<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    materiaPrima: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({
    categoria: '',
    fabricante: '',
    cod_mp: '',
    cod_comercial: '',
    desc_mp: '',
    larg_mp: '',
    preco_m2: '',
    ativo: true,
});

watch(
    () => props.show,
    (mostrando) => {
        if (!mostrando) return;

        if (props.materiaPrima) {
            form.categoria = props.materiaPrima.categoria ?? '';
            form.fabricante = props.materiaPrima.fabricante ?? '';
            form.cod_mp = props.materiaPrima.codMp ?? '';
            form.cod_comercial = props.materiaPrima.codComercial ?? '';
            form.desc_mp = props.materiaPrima.descMp ?? '';
            form.larg_mp = props.materiaPrima.largMp !== null ? String(props.materiaPrima.largMp) : '';
            form.preco_m2 = String(props.materiaPrima.precoM2);
            form.ativo = props.materiaPrima.ativo;
        } else {
            form.reset();
            form.ativo = true;
        }
    },
);

function fechar() {
    form.clearErrors();
    emit('close');
}

function salvar() {
    const opcoes = { preserveScroll: true, onSuccess: () => fechar() };

    if (props.materiaPrima) {
        form.patch(route('etiquetas.materiaPrima.update', props.materiaPrima.id), opcoes);
    } else {
        form.post(route('etiquetas.materiaPrima.store'), opcoes);
    }
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="fechar">
        <form class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">{{ materiaPrima ? 'Editar Matéria-Prima' : 'Nova Matéria-Prima' }}</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="mp_desc" value="Descrição *" />
                    <TextInput id="mp_desc" v-model="form.desc_mp" class="mt-1 block w-full" required />
                    <InputError :message="form.errors.desc_mp" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_categoria" value="Categoria" />
                    <TextInput id="mp_categoria" v-model="form.categoria" class="mt-1 block w-full" />
                    <InputError :message="form.errors.categoria" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_fabricante" value="Fabricante" />
                    <TextInput id="mp_fabricante" v-model="form.fabricante" class="mt-1 block w-full" />
                    <InputError :message="form.errors.fabricante" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_cod" value="Código MP" />
                    <TextInput id="mp_cod" v-model="form.cod_mp" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cod_mp" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_cod_comercial" value="Código Comercial" />
                    <TextInput id="mp_cod_comercial" v-model="form.cod_comercial" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cod_comercial" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_larg" value="Largura (mm)" />
                    <TextInput id="mp_larg" v-model="form.larg_mp" type="number" min="0" step="0.01" class="mt-1 block w-full" />
                    <InputError :message="form.errors.larg_mp" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mp_preco" value="Preço R$/m² *" />
                    <TextInput id="mp_preco" v-model="form.preco_m2" type="number" min="0" step="0.0001" class="mt-1 block w-full" required />
                    <InputError :message="form.errors.preco_m2" class="mt-1" />
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="form.ativo" type="checkbox" class="rounded border-gray-300 text-teal focus:ring-cyan" />
                        Ativa
                    </label>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">{{ materiaPrima ? 'Salvar Alterações' : 'Cadastrar' }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
