<script setup>
/**
 * As conversas anteriores, agrupadas por data (Hoje, Ontem, Últimos 7 dias, Anteriores),
 * com busca pelo título. Usada no histórico do painel e na lateral da tela cheia.
 */
import { computed, ref } from 'vue';
import { conversaId, conversas } from '@/composables/useAssistenteIa';

const emit = defineEmits(['escolher']);

const busca = ref('');

function inicioDoDia(d) {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x.getTime();
}

const grupos = computed(() => {
    if (!conversas.value) {
        return [];
    }
    const termo = busca.value.trim().toLocaleLowerCase('pt-BR');
    const hoje = inicioDoDia(new Date());
    const dia = 86400000;
    const rotulos = ['Hoje', 'Ontem', 'Últimos 7 dias', 'Últimos 30 dias', 'Anteriores'];
    const porGrupo = rotulos.map((rotulo) => ({ rotulo, itens: [] }));

    for (const c of conversas.value) {
        if (termo && !c.titulo?.toLocaleLowerCase('pt-BR').includes(termo)) {
            continue;
        }
        const diff = (hoje - inicioDoDia(c.atualizadaEm)) / dia;
        const i = diff <= 0 ? 0 : diff <= 1 ? 1 : diff <= 7 ? 2 : diff <= 30 ? 3 : 4;
        porGrupo[i].itens.push(c);
    }

    return porGrupo.filter((g) => g.itens.length);
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="px-3 pb-2 pt-3">
            <input
                v-model="busca"
                type="search"
                class="w-full rounded border-gray-300 py-1.5 text-xs focus:border-cyan focus:ring-cyan"
                placeholder="Buscar nas conversas…"
            />
        </div>
        <div class="flex-1 overflow-y-auto pb-3">
            <p v-if="conversas === null" class="px-4 py-3 text-sm text-gray-500">Carregando…</p>
            <p v-else-if="!grupos.length" class="px-4 py-3 text-sm text-gray-500">
                {{ busca.trim() ? 'Nenhuma conversa com esse texto.' : 'Nenhuma conversa ainda.' }}
            </p>
            <section v-for="g in grupos" :key="g.rotulo">
                <p class="px-4 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">{{ g.rotulo }}</p>
                <ul>
                    <li v-for="c in g.itens" :key="c.id">
                        <button
                            type="button"
                            class="flex w-full items-baseline gap-3 border-l-2 px-4 py-2 text-left text-sm transition hover:bg-gray-100"
                            :class="c.id === conversaId ? 'border-cyan bg-cyan/10 text-gray-900' : 'border-transparent text-gray-700'"
                            :title="c.titulo"
                            @click="emit('escolher', c.id)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{ c.titulo }}</span>
                            <span class="shrink-0 text-[0.65rem] text-gray-400">{{ c.quando }}</span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
