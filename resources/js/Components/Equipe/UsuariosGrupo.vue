<script setup>
import { computed } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import SegmentoChips from '@/Components/Equipe/SegmentoChips.vue';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    grupo: { type: Object, required: true },
    podeGerenciar: { type: Boolean, default: false },
    selecionados: { type: Array, default: () => [] },
    usuarioLogadoId: { type: Number, required: true },
    podeSimular: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-selecionado', 'toggle-grupo', 'editar', 'trocar-senha', 'toggle-status', 'excluir', 'simular']);

const idsGrupo = computed(() => props.grupo.usuarios.map((u) => u.id));

const todosSelecionados = computed(() => idsGrupo.value.length > 0 && idsGrupo.value.every((id) => props.selecionados.includes(id)));
const algunsSelecionados = computed(() => !todosSelecionados.value && idsGrupo.value.some((id) => props.selecionados.includes(id)));

function formatarLogin(iso) {
    if (!iso) return 'Nunca';
    return new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <div class="overflow-hidden rounded border border-gray-200">
        <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 text-gray-400">
                <circle cx="9" cy="7" r="3" />
                <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                <circle cx="17" cy="8" r="2.5" />
                <path d="M16 14.2c2.9.4 5 2.7 5 5.8" stroke-linecap="round" />
            </svg>
            <strong class="text-sm text-gray-800">{{ grupo.supervisorNome }}</strong>
            <span class="text-xs text-gray-400">({{ grupo.usuarios.length }} usuário{{ grupo.usuarios.length !== 1 ? 's' : '' }})</span>
        </div>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr class="tbl-head-row">
                        <th v-if="podeGerenciar" class="tbl-th w-10">
                            <input
                                type="checkbox"
                                :checked="todosSelecionados"
                                :indeterminate="algunsSelecionados"
                                class="rounded border-gray-300 text-teal focus:ring-cyan"
                                @change="emit('toggle-grupo', idsGrupo, $event.target.checked)"
                            />
                        </th>
                        <th class="tbl-th">Usuário</th>
                        <th class="tbl-th">Perfil</th>
                        <th class="tbl-th">Status</th>
                        <th class="tbl-th">Segmento</th>
                        <th class="tbl-th">Localização</th>
                        <th class="tbl-th">Códigos</th>
                        <th class="tbl-th">Último Login</th>
                        <th v-if="podeGerenciar" class="tbl-th">Ações</th>
                    </tr>
                </thead>
                <tbody class="tbl-body">
                    <tr v-for="usuario in grupo.usuarios" :key="usuario.id" class="tbl-row">
                        <td v-if="podeGerenciar" class="tbl-td">
                            <input
                                type="checkbox"
                                :checked="selecionados.includes(usuario.id)"
                                class="rounded border-gray-300 text-teal focus:ring-cyan"
                                @change="emit('toggle-selecionado', usuario.id)"
                            />
                        </td>
                        <td class="tbl-td">
                            <div class="flex items-center justify-center gap-1.5">
                                <span
                                    class="h-2 w-2 shrink-0 rounded-full"
                                    :class="usuario.online ? 'bg-emerald-500' : 'bg-gray-300'"
                                    :title="usuario.online ? 'Online agora' : 'Offline'"
                                />
                                <span class="font-medium leading-4 text-gray-800">{{ usuario.nome }}</span>
                            </div>
                            <div class="tbl-sub">{{ usuario.email }}</div>
                        </td>
                        <td class="tbl-td">
                            <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-gray-600">
                                {{ ROTULOS_PERFIL[usuario.perfil] || usuario.perfil }}
                            </span>
                        </td>
                        <td class="tbl-td">
                            <StatusPill :tone="usuario.ativo ? 'ok' : 'danger'" size="sm">{{ usuario.ativo ? 'Ativo' : 'Inativo' }}</StatusPill>
                        </td>
                        <td class="tbl-td">
                            <div class="flex justify-center">
                                <SegmentoChips :segmentos="usuario.segmentos" />
                            </div>
                        </td>
                        <td class="tbl-td">
                            <div v-if="usuario.estado">{{ usuario.estado }}</div>
                            <div v-if="usuario.tipoUsuario" class="tbl-sub">{{ usuario.tipoUsuario === 'INTERNO' ? 'Interno' : 'Externo' }}</div>
                        </td>
                        <td class="tbl-td">
                            <div v-if="usuario.codVendedor">V: {{ usuario.codVendedor }}</div>
                            <div v-if="usuario.codSuper" class="tbl-sub">S: {{ usuario.codSuper }}</div>
                        </td>
                        <td class="tbl-td">{{ formatarLogin(usuario.ultimoLogin) }}</td>
                        <td v-if="podeGerenciar" class="tbl-td">
                            <div class="tbl-acoes">
                                <button
                                    type="button"
                                    title="Editar"
                                    class="tbl-acao tbl-acao-teal"
                                    @click="emit('editar', usuario)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <!-- Simular: só admin, e não faz sentido pra si mesmo, outro admin ou inativo -->
                                <button
                                    v-if="podeSimular && usuario.id !== usuarioLogadoId && usuario.perfil !== 'admin' && usuario.ativo"
                                    type="button"
                                    title="Ver o sistema como este usuário"
                                    class="tbl-acao tbl-acao-cyan"
                                    @click="emit('simular', usuario)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="2.5" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    title="Trocar senha"
                                    class="tbl-acao tbl-acao-neutro"
                                    @click="emit('trocar-senha', usuario)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="8" cy="15" r="3" />
                                        <path d="M10.5 12.5 20 3M16 7l3 3M13 10l2 2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    :title="usuario.ativo ? 'Desativar' : 'Ativar'"
                                    class="tbl-acao tbl-acao-amber"
                                    @click="emit('toggle-status', usuario)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M12 3v7" stroke-linecap="round" />
                                        <path d="M6.5 6.5a7 7 0 1 0 11 0" stroke-linecap="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="usuario.id !== usuarioLogadoId"
                                    type="button"
                                    title="Excluir"
                                    class="tbl-acao tbl-acao-danger"
                                    @click="emit('excluir', usuario)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
