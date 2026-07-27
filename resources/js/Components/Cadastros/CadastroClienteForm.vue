<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    opcoes: { type: Object, required: true },
});

const form = useForm({
    cnpj_faturamento: '',
    cadastro_raiz_opcao: '',
    inscricao_estadual: '',
    razao_social: '',
    nome_fantasia: '',
    condicao_pagamento: '',
    grupo_vendas: 'nao',
    grupo_vendas_codigo: '',
    tabela_preco: 'nao',
    tabela_preco_codigo: '',
    segmento_atuacao: '',
    endereco: '',
    complemento: '',
    cep: '',
    bairro: '',
    municipio: '',
    estado: '',
    entrega_diferente: false,
    entrega_endereco: '',
    entrega_complemento: '',
    entrega_cep: '',
    entrega_bairro: '',
    entrega_municipio: '',
    entrega_estado: '',
    telefone: '',
    email: '',
    observacoes: '',
});

const soEntrega = computed(() => form.cadastro_raiz_opcao === 'nova_entrega');
const mostrarEntrega = computed(() => form.entrega_diferente || soEntrega.value);
const fieldClass = 'mt-1 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan';

function submit() {
    form.transform((data) => ({
        ...data,
        entrega_diferente: !!data.entrega_diferente,
    })).post(route('cadastros.clientes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.grupo_vendas = 'nao';
            form.tabela_preco = 'nao';
            form.entrega_diferente = false;
        },
    });
}
</script>

<template>
    <DarkCard title="Cadastro de Cliente" subtitle="Solicitação para o setor de Cadastros gerar o cliente no TOTVS">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" stroke-linecap="round" />
                <circle cx="9" cy="7" r="3" />
                <path d="M19 8v6M22 11h-6" stroke-linecap="round" />
            </svg>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <section>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Empresa</p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel value="CNPJ faturamento *" class="!text-xs" />
                        <TextInput v-model="form.cnpj_faturamento" class="mt-1 block w-full text-xs" placeholder="00.000.000/0000-00" required />
                        <InputError :message="form.errors.cnpj_faturamento" />
                    </div>
                    <div>
                        <InputLabel value="Se já existe grupo na carteira" class="!text-xs" />
                        <div class="mt-1 flex flex-wrap gap-3 text-xs text-gray-700">
                            <label class="inline-flex items-center gap-1.5"><input v-model="form.cadastro_raiz_opcao" type="radio" value="filial" class="border-gray-300 text-cyan focus:ring-cyan" /> Nova filial</label>
                            <label class="inline-flex items-center gap-1.5"><input v-model="form.cadastro_raiz_opcao" type="radio" value="nova_entrega" class="border-gray-300 text-cyan focus:ring-cyan" /> Só endereço de entrega</label>
                            <button v-if="form.cadastro_raiz_opcao" type="button" class="text-[0.65rem] text-gray-500 underline" @click="form.cadastro_raiz_opcao = ''">Limpar</button>
                        </div>
                    </div>
                    <div v-if="!soEntrega">
                        <InputLabel value="Inscrição estadual *" class="!text-xs" />
                        <TextInput v-model="form.inscricao_estadual" class="mt-1 block w-full text-xs" required />
                        <InputError :message="form.errors.inscricao_estadual" />
                    </div>
                    <div>
                        <InputLabel value="Razão social" class="!text-xs" />
                        <TextInput v-model="form.razao_social" class="mt-1 block w-full text-xs" />
                    </div>
                    <div>
                        <InputLabel value="Nome fantasia" class="!text-xs" />
                        <TextInput v-model="form.nome_fantasia" class="mt-1 block w-full text-xs" />
                    </div>
                    <div v-if="!soEntrega">
                        <InputLabel value="Condição de pagamento *" class="!text-xs" />
                        <TextInput v-model="form.condicao_pagamento" class="mt-1 block w-full text-xs" placeholder="Ex.: 28 DDL" required />
                        <InputError :message="form.errors.condicao_pagamento" />
                    </div>
                    <div v-if="!soEntrega" class="sm:col-span-2 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Grupo de vendas" class="!text-xs" />
                            <div class="mt-1 flex gap-4 text-xs">
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.grupo_vendas" type="radio" value="nao" class="border-gray-300 text-cyan focus:ring-cyan" /> Não</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.grupo_vendas" type="radio" value="sim" class="border-gray-300 text-cyan focus:ring-cyan" /> Sim</label>
                            </div>
                            <TextInput v-if="form.grupo_vendas === 'sim'" v-model="form.grupo_vendas_codigo" class="mt-2 block w-full text-xs" placeholder="Código do grupo" />
                        </div>
                        <div>
                            <InputLabel value="Tabela de preço" class="!text-xs" />
                            <div class="mt-1 flex gap-4 text-xs">
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.tabela_preco" type="radio" value="nao" class="border-gray-300 text-cyan focus:ring-cyan" /> Não</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.tabela_preco" type="radio" value="sim" class="border-gray-300 text-cyan focus:ring-cyan" /> Sim</label>
                            </div>
                            <TextInput v-if="form.tabela_preco === 'sim'" v-model="form.tabela_preco_codigo" class="mt-2 block w-full text-xs" placeholder="Identificação da tabela" />
                        </div>
                    </div>
                    <div v-if="!soEntrega" class="sm:col-span-2">
                        <InputLabel value="Segmento de atuação *" class="!text-xs" />
                        <select v-model="form.segmento_atuacao" :class="fieldClass" required>
                            <option value="">Selecione</option>
                            <option v-for="s in opcoes.segmentos" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <InputError :message="form.errors.segmento_atuacao" />
                    </div>
                </div>
            </section>

            <section v-if="!soEntrega">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cobrança / faturamento</p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel value="Endereço *" class="!text-xs" />
                        <TextInput v-model="form.endereco" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Complemento" class="!text-xs" />
                        <TextInput v-model="form.complemento" class="mt-1 block w-full text-xs" />
                    </div>
                    <div>
                        <InputLabel value="CEP *" class="!text-xs" />
                        <TextInput v-model="form.cep" class="mt-1 block w-full text-xs" placeholder="00000-000" required />
                    </div>
                    <div>
                        <InputLabel value="Bairro *" class="!text-xs" />
                        <TextInput v-model="form.bairro" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Município *" class="!text-xs" />
                        <TextInput v-model="form.municipio" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Estado *" class="!text-xs" />
                        <select v-model="form.estado" :class="fieldClass" required>
                            <option value="">UF</option>
                            <option v-for="uf in opcoes.estados" :key="uf" :value="uf">{{ uf }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <section>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Entrega</p>
                <label v-if="!soEntrega" class="mt-2 inline-flex items-center gap-2 text-xs text-gray-700">
                    <input v-model="form.entrega_diferente" type="checkbox" class="rounded border-gray-300 text-cyan focus:ring-cyan" />
                    Endereço de entrega diferente do faturamento
                </label>
                <p v-else class="mt-1 text-xs text-amber-700">Modo entrega: preencha só os campos abaixo.</p>
                <div v-if="mostrarEntrega" class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel value="Endereço de entrega *" class="!text-xs" />
                        <TextInput v-model="form.entrega_endereco" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Complemento" class="!text-xs" />
                        <TextInput v-model="form.entrega_complemento" class="mt-1 block w-full text-xs" />
                    </div>
                    <div>
                        <InputLabel value="CEP *" class="!text-xs" />
                        <TextInput v-model="form.entrega_cep" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Bairro *" class="!text-xs" />
                        <TextInput v-model="form.entrega_bairro" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Município *" class="!text-xs" />
                        <TextInput v-model="form.entrega_municipio" class="mt-1 block w-full text-xs" required />
                    </div>
                    <div>
                        <InputLabel value="Estado *" class="!text-xs" />
                        <select v-model="form.entrega_estado" :class="fieldClass" required>
                            <option value="">UF</option>
                            <option v-for="uf in opcoes.estados" :key="uf" :value="uf">{{ uf }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <section v-if="!soEntrega">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Contato</p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Telefone *" class="!text-xs" />
                        <TextInput v-model="form.telefone" class="mt-1 block w-full text-xs" placeholder="(00) 00000-0000" required />
                    </div>
                    <div>
                        <InputLabel value="E-mail *" class="!text-xs" />
                        <TextInput v-model="form.email" type="email" class="mt-1 block w-full text-xs" required />
                    </div>
                </div>
            </section>

            <section>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Observações</p>
                <textarea v-model="form.observacoes" rows="3" class="mt-2 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan" placeholder="Opcional" />
            </section>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Gerar solicitação</PrimaryButton>
            </div>
        </form>
    </DarkCard>
</template>
