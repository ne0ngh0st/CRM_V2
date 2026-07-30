<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import StatusPill from '@/Components/StatusPill.vue';
import FilterField from '@/Components/FilterField.vue';
import DarkCard from '@/Components/DarkCard.vue';
import UsuariosGrupo from '@/Components/Equipe/UsuariosGrupo.vue';
import OrganogramaTree from '@/Components/Equipe/OrganogramaTree.vue';
import NovoUsuarioModal from '@/Components/Equipe/NovoUsuarioModal.vue';
import EditarUsuarioModal from '@/Components/Equipe/EditarUsuarioModal.vue';
import TrocarSenhaModal from '@/Components/Equipe/TrocarSenhaModal.vue';
import SupervisorMassaModal from '@/Components/Equipe/SupervisorMassaModal.vue';
import ExcluirUsuarioModal from '@/Components/Equipe/ExcluirUsuarioModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    role: String,
    podeGerenciar: Boolean,
    totalUsuarios: Number,
    totalOnline: Number,
    usuarios: Array,
    filtros: Object,
    opcoes: Object,
    organograma: Array,
});

const aba = ref('lista');

const filtros = reactive({
    busca: props.filtros.busca || '',
    perfil: props.filtros.perfil || '',
    supervisor: props.filtros.supervisor || '',
    estado: props.filtros.estado || '',
    tipo: props.filtros.tipo || '',
    status: props.filtros.status || '',
    online: props.filtros.online || '',
    login: props.filtros.login || '',
});

function aplicarFiltros() {
    router.get(route('equipe.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['usuarios', 'totalUsuarios', 'totalOnline'],
    });
}

const temFiltrosAtivos = computed(() =>
    ['busca', 'perfil', 'supervisor', 'estado', 'tipo', 'status', 'online', 'login'].some((k) => filtros[k] !== ''),
);

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, { busca: '', perfil: '', supervisor: '', estado: '', tipo: '', status: '', online: '', login: '' });
    aplicarFiltros();
}

// Presença online: refresh leve (45s) sem perder filtros/scroll — sem custo real na escala da equipe (~200 usuários).
let intervaloPresenca = null;
onMounted(() => {
    intervaloPresenca = setInterval(() => {
        router.reload({ only: ['usuarios', 'totalOnline'], preserveScroll: true, preserveState: true });
    }, 45000);
});
onUnmounted(() => clearInterval(intervaloPresenca));

// Seleção pra reatribuição de supervisor em massa
const selecionados = ref([]);

function toggleSelecionado(id) {
    const idx = selecionados.value.indexOf(id);
    if (idx === -1) selecionados.value.push(id);
    else selecionados.value.splice(idx, 1);
}

function toggleGrupo(ids, checked) {
    if (checked) {
        ids.forEach((id) => {
            if (!selecionados.value.includes(id)) selecionados.value.push(id);
        });
    } else {
        selecionados.value = selecionados.value.filter((id) => !ids.includes(id));
    }
}

const usuariosSelecionados = computed(() => {
    const flat = props.usuarios.flatMap((g) => g.usuarios);
    return flat.filter((u) => selecionados.value.includes(u.id));
});

// Modais
const modalNovo = ref(false);
const modalEditar = ref(false);
const modalSenha = ref(false);
const modalMassa = ref(false);
const modalExcluir = ref(false);
const usuarioAtivo = ref(null);

function abrirEditar(usuario) {
    usuarioAtivo.value = usuario;
    modalEditar.value = true;
}

function abrirSenha(usuario) {
    usuarioAtivo.value = usuario;
    modalSenha.value = true;
}

function abrirExcluir(usuario) {
    usuarioAtivo.value = usuario;
    modalExcluir.value = true;
}

function alternarStatus(usuario) {
    router.patch(route('equipe.status', usuario.id), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Equipe" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Equipe">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <circle cx="9" cy="7" r="4" />
                            <path d="M2 21c0-4.4 3.6-8 7-8s7 3.6 7 8" stroke-linecap="round" />
                            <circle cx="18" cy="9" r="3" />
                            <path d="M17 21c0-3.5-1.4-6.4-3.5-7.9" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        {{ totalUsuarios }} usuário{{ totalUsuarios !== 1 ? 's' : '' }}
                        <span v-if="!podeGerenciar"> · visualização da sua equipe</span>
                    </template>
                    <template #meta>
                        <StatusPill tone="ok" surface="dark">{{ totalOnline }} online agora</StatusPill>
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[180px] max-w-[260px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Nome, e-mail ou código..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Perfil" :model-value="filtros.perfil" @update:model-value="(v) => { filtros.perfil = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="p in opcoes.perfis" :key="p" :value="p">{{ ROTULOS_PERFIL[p] || p }}</option>
                        </FilterField>

                        <FilterField v-if="podeGerenciar" label="Supervisor" :model-value="filtros.supervisor" @update:model-value="(v) => { filtros.supervisor = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="s in opcoes.supervisores" :key="s.codVendedor" :value="s.codVendedor">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField label="Estado" :model-value="filtros.estado" @update:model-value="(v) => { filtros.estado = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="e in opcoes.estados" :key="e" :value="e">{{ e }}</option>
                        </FilterField>

                        <FilterField label="Tipo" :model-value="filtros.tipo" @update:model-value="(v) => { filtros.tipo = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="INTERNO">Interno</option>
                            <option value="EXTERNO">Externo</option>
                        </FilterField>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                        </FilterField>

                        <FilterField label="Presença" :model-value="filtros.online" @update:model-value="(v) => { filtros.online = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="sim">Online agora</option>
                        </FilterField>

                        <FilterField label="Último Login" :model-value="filtros.login" @update:model-value="(v) => { filtros.login = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="hoje">Hoje</option>
                            <option value="semana">Esta semana</option>
                            <option value="mes">Este mês</option>
                            <option value="nunca">Nunca logou</option>
                        </FilterField>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <div v-if="podeGerenciar" class="flex gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm font-medium"
                        :class="aba === 'lista' ? 'border-teal bg-teal text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                        @click="aba = 'lista'"
                    >
                        Lista de Usuários
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm font-medium"
                        :class="aba === 'organograma' ? 'border-teal bg-teal text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                        @click="aba = 'organograma'"
                    >
                        Organograma
                    </button>
                </div>

                <DarkCard v-if="aba === 'lista'" title="Lista de Usuários" :subtitle="`${totalUsuarios} usuário${totalUsuarios !== 1 ? 's' : ''}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <ExportarExcelButton
                            rota="equipe.exportar"
                            :filtros="filtros"
                            :tem-filtros-ativos="temFiltrosAtivos"
                        />
                        <template v-if="podeGerenciar">
                            <button
                                v-if="selecionados.length"
                                type="button"
                                class="rounded bg-white/10 px-3 py-1 text-xs font-medium text-gray-100 hover:bg-white/20"
                                @click="modalMassa = true"
                            >
                                Reatribuir Supervisor ({{ selecionados.length }})
                            </button>
                            <button type="button" class="rounded bg-teal px-3 py-1 text-xs font-medium text-white hover:bg-teal/90" @click="modalNovo = true">
                                + Novo Usuário
                            </button>
                        </template>
                    </template>

                    <div v-if="usuarios.length" class="flex flex-col gap-4">
                        <UsuariosGrupo
                            v-for="grupo in usuarios"
                            :key="grupo.supervisorCod ?? '_sem_supervisor'"
                            :grupo="grupo"
                            :pode-gerenciar="podeGerenciar"
                            :selecionados="selecionados"
                            :usuario-logado-id="$page.props.auth.user.id"
                            @toggle-selecionado="toggleSelecionado"
                            @toggle-grupo="toggleGrupo"
                            @editar="abrirEditar"
                            @trocar-senha="abrirSenha"
                            @toggle-status="alternarStatus"
                            @excluir="abrirExcluir"
                        />
                    </div>
                    <p v-else class="text-sm text-gray-400">Nenhum usuário encontrado com os filtros atuais.</p>
                </DarkCard>

                <DarkCard v-else title="Organograma" subtitle="Hierarquia gerada a partir dos códigos de supervisor/vendedor">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="9" y="3" width="6" height="4" rx="1" />
                            <rect x="3" y="17" width="6" height="4" rx="1" />
                            <rect x="15" y="17" width="6" height="4" rx="1" />
                            <path d="M12 7v4M12 11H6v6M12 11h6v6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <OrganogramaTree v-if="organograma?.length" :nos="organograma" />
                    <p v-else class="text-sm text-gray-400">Sem hierarquia cadastrada ainda.</p>
                </DarkCard>
            </div>
        </div>

        <template v-if="podeGerenciar">
            <NovoUsuarioModal :show="modalNovo" :opcoes="opcoes" @close="modalNovo = false" />
            <EditarUsuarioModal :show="modalEditar" :usuario="usuarioAtivo" :opcoes="opcoes" @close="modalEditar = false" />
            <TrocarSenhaModal :show="modalSenha" :usuario="usuarioAtivo" @close="modalSenha = false" />
            <SupervisorMassaModal :show="modalMassa" :usuarios="usuariosSelecionados" :opcoes="opcoes" @close="modalMassa = false; selecionados = []" />
            <ExcluirUsuarioModal :show="modalExcluir" :usuario="usuarioAtivo" @close="modalExcluir = false" />
        </template>
    </AuthenticatedLayout>
</template>
