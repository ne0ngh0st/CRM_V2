<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    agendamentos: { type: Array, required: true },
    statusRoute: { type: String, default: 'carteira.agendamentoStatus' },
});

const hoje = new Date();
const ano = ref(hoje.getFullYear());
const mes = ref(hoje.getMonth()); // 0-11
const filtroStatus = ref('');

const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const DIAS_SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

const TONS_STATUS = {
    agendado: 'warn',
    realizado: 'ok',
    cancelado: 'danger',
};

const ROTULOS_STATUS = {
    agendado: 'Agendado',
    realizado: 'Realizado',
    cancelado: 'Cancelado',
};

const filtrados = computed(() => {
    if (!filtroStatus.value) return props.agendamentos;
    return props.agendamentos.filter((a) => a.status === filtroStatus.value);
});

const tituloMes = computed(() => `${MESES[mes.value]} ${ano.value}`);

const celulas = computed(() => {
    const primeiro = new Date(ano.value, mes.value, 1);
    const ultimo = new Date(ano.value, mes.value + 1, 0);
    const offset = primeiro.getDay();
    const totalDias = ultimo.getDate();
    const cells = [];

    for (let i = 0; i < offset; i++) {
        cells.push({ key: `e-${i}`, vazio: true });
    }

    for (let dia = 1; dia <= totalDias; dia++) {
        const ymd = `${ano.value}-${String(mes.value + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const itens = filtrados.value.filter((a) => a.dia === ymd);
        const isHoje = ymd === hoje.toISOString().slice(0, 10);
        cells.push({ key: ymd, vazio: false, dia, ymd, itens, isHoje });
    }

    return cells;
});

const proximos = computed(() => {
    const agora = Date.now();
    return filtrados.value
        .filter((a) => a.status === 'agendado' && new Date(a.dataAgendamento).getTime() >= agora - 86400000)
        .slice(0, 12);
});

function mesAnterior() {
    if (mes.value === 0) {
        mes.value = 11;
        ano.value -= 1;
    } else {
        mes.value -= 1;
    }
}

function mesProximo() {
    if (mes.value === 11) {
        mes.value = 0;
        ano.value += 1;
    } else {
        mes.value += 1;
    }
}

function alterarStatus(agendamento, status) {
    router.patch(route(props.statusRoute, agendamento.id), { status }, {
        preserveScroll: true,
        preserveState: true,
        only: ['agendamentos'],
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <DarkCard title="Calendário de Agendamentos" :subtitle="`${agendamentos.length} agendamento${agendamentos.length !== 1 ? 's' : ''} no período`">
            <template #icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                    <rect x="3" y="4.5" width="18" height="16" rx="1.5" />
                    <path d="M3 9h18M8 3v3M16 3v3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </template>
            <template #actions>
                <div class="flex items-center gap-2">
                    <select
                        v-model="filtroStatus"
                        class="rounded border border-white/20 bg-white/10 py-1 pl-2 pr-7 text-xs text-gray-200 focus:border-cyan focus:ring-cyan"
                    >
                        <option value="" class="text-gray-800">Todos os status</option>
                        <option value="agendado" class="text-gray-800">Agendados</option>
                        <option value="realizado" class="text-gray-800">Realizados</option>
                        <option value="cancelado" class="text-gray-800">Cancelados</option>
                    </select>
                    <button type="button" class="rounded border border-white/20 px-2 py-1 text-xs text-gray-200 hover:bg-white/10" @click="mesAnterior">‹</button>
                    <span class="min-w-[140px] text-center text-xs font-semibold uppercase tracking-wide text-gray-300">{{ tituloMes }}</span>
                    <button type="button" class="rounded border border-white/20 px-2 py-1 text-xs text-gray-200 hover:bg-white/10" @click="mesProximo">›</button>
                </div>
            </template>

            <div class="grid grid-cols-7 gap-px overflow-hidden rounded border border-gray-200 bg-gray-200">
                <div
                    v-for="d in DIAS_SEMANA"
                    :key="d"
                    class="bg-gray-50 px-1 py-2 text-center text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500"
                >
                    {{ d }}
                </div>
                <div
                    v-for="cell in celulas"
                    :key="cell.key"
                    class="min-h-[88px] bg-white p-1.5"
                    :class="cell.vazio ? 'bg-gray-50' : ''"
                >
                    <template v-if="!cell.vazio">
                        <p
                            class="text-xs font-semibold"
                            :class="cell.isHoje ? 'text-cyan' : 'text-gray-500'"
                        >
                            {{ cell.dia }}
                        </p>
                        <div class="mt-1 space-y-0.5">
                            <div
                                v-for="item in cell.itens.slice(0, 3)"
                                :key="item.id"
                                class="truncate rounded px-1 py-0.5 text-[0.65rem] font-medium"
                                :class="{
                                    'bg-amber-50 text-amber-800': item.status === 'agendado',
                                    'bg-emerald-50 text-emerald-800': item.status === 'realizado',
                                    'bg-red-50 text-red-700': item.status === 'cancelado',
                                }"
                                :title="`${item.hora} · ${item.clienteNome}`"
                            >
                                {{ item.hora }} {{ item.clienteNome }}
                            </div>
                            <p v-if="cell.itens.length > 3" class="text-[0.6rem] text-gray-400">+{{ cell.itens.length - 3 }}</p>
                        </div>
                    </template>
                </div>
            </div>
        </DarkCard>

        <DarkCard title="Próximos Agendamentos" :subtitle="`${proximos.length} pendente${proximos.length !== 1 ? 's' : ''}`">
            <template #icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                    <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </template>

            <p v-if="!proximos.length" class="text-sm text-gray-400">Nenhum agendamento pendente à frente.</p>
            <ul v-else class="max-h-80 space-y-2 overflow-y-auto">
                <li
                    v-for="item in proximos"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-gray-200 px-3 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-800">{{ item.clienteNome }}</p>
                        <p class="text-xs text-gray-500">{{ item.dataLabel }} · {{ item.autor }}</p>
                        <p v-if="item.observacao" class="mt-0.5 truncate text-xs text-gray-400">{{ item.observacao }}</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <StatusPill :tone="TONS_STATUS[item.status]">{{ ROTULOS_STATUS[item.status] }}</StatusPill>
                        <button
                            type="button"
                            title="Marcar como realizado"
                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:bg-teal/10 hover:text-teal"
                            @click="alterarStatus(item, 'realizado')"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                                <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            title="Cancelar agendamento"
                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-red-400 hover:bg-red-50 hover:text-red-600"
                            @click="alterarStatus(item, 'cancelado')"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                                <path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                </li>
            </ul>
        </DarkCard>
    </div>
</template>
