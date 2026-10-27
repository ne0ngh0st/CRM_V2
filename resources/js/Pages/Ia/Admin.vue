<script setup>
/**
 * Admin do assistente de IA: chamados que ele abriu, custo e respostas marcadas como
 * ruins. É daqui que sai o próximo ajuste no manual (resources/ia/manual.md).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';

const props = defineProps({
    status: { type: String, required: true },
    chamados: { type: Array, required: true },
    uso: { type: Object, required: true },
    chamadoAberto: { type: Number, default: null },
});

const status = ref(props.status);
watch(status, () => router.get(route('ia.admin'), { status: status.value }, { preserveScroll: true, replace: true }));

const usd = (n) => `US$ ${Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pctTeto = computed(() => (props.uso.tetoMes > 0 ? Math.round((props.uso.custoMes / props.uso.tetoMes) * 100) : 0));

// ---- responder chamado
const respondendo = ref(null);
const form = useForm({ resposta: '' });
function abrirResposta(c) {
    respondendo.value = c;
    form.resposta = '';
    form.clearErrors();
}
function enviarResposta() {
    form.post(route('ia.admin.responder', respondendo.value.id), {
        preserveScroll: true,
        onSuccess: () => (respondendo.value = null),
    });
}

// ---- ler conversa
const conversa = ref(null);
async function verConversa(id) {
    const res = await fetch(route('ia.admin.conversa', id), { headers: { Accept: 'application/json' } });
    conversa.value = res.ok ? await res.json() : null;
}

onMounted(() => {
    const c = props.chamados.find((x) => x.id === props.chamadoAberto);
    if (c?.conversaId) {
        verConversa(c.conversaId);
    }
});
</script>

<template>
    <Head title="Assistente de IA" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Assistente de IA">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <path d="M4.6 6.2a1.8 1.8 0 0 1 1.8-1.8h11.2a1.8 1.8 0 0 1 1.8 1.8v8.2a1.8 1.8 0 0 1-1.8 1.8H11l-4.2 3.4v-3.4h-.4a1.8 1.8 0 0 1-1.8-1.8z" />
                        <path d="M12 7.6l.9 1.9 1.9.9-1.9.9-.9 1.9-.9-1.9-1.9-.9 1.9-.9z" />
                    </svg>
                </template>
                <template #subtitle>Chamados abertos pelo assistente, custo do mês e respostas marcadas como ruins.</template>
                <template #meta>
                    <StatusPill :tone="uso.habilitado ? 'ok' : 'warn'" surface="dark">
                        {{ uso.habilitado ? `Ligado · ${uso.liberados} beta tester(s)` : 'Desligado · só admin' }}
                    </StatusPill>
                    <StatusPill tone="neutral" surface="dark">{{ uso.modelo }}</StatusPill>
                </template>
                <template #filtros>
                    <FilterField v-model="status" label="Chamados">
                        <option value="aberto">Abertos</option>
                        <option value="resolvido">Resolvidos</option>
                    </FilterField>
                </template>
            </PageHero>

            <div class="grid gap-4 xl:grid-cols-3">
                <DarkCard class="xl:col-span-2" title="Chamados" :subtitle="status === 'aberto' ? 'Esperando resposta' : 'Já respondidos'">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5"><path d="M5 5h14v10H9l-4 4z" stroke-linejoin="round" /></svg>
                    </template>

                    <p v-if="!chamados.length" class="text-sm text-gray-500">Nenhum chamado {{ status === 'aberto' ? 'aberto' : 'resolvido' }}.</p>
                    <ul v-else class="max-h-[36rem] space-y-2 overflow-y-auto">
                        <li
                            v-for="c in chamados"
                            :key="c.id"
                            class="rounded border px-3 py-2"
                            :class="c.id === chamadoAberto ? 'border-cyan bg-cyan/5' : 'border-gray-200'"
                        >
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="font-semibold text-gray-700">#{{ c.id }} · {{ c.usuario }}</span>
                                <StatusPill size="sm" tone="neutral">{{ c.categoria }}</StatusPill>
                                <span>{{ c.abertoEm }}</span>
                                <span v-if="c.tela" class="truncate text-gray-400">{{ c.tela }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700">{{ c.resumo }}</p>
                            <p v-if="c.resposta" class="mt-1.5 border-l-2 border-teal pl-2 text-sm text-gray-600">
                                {{ c.resposta }} <span class="text-xs text-gray-400">({{ c.resolvidoEm }})</span>
                            </p>
                            <div class="mt-2 flex gap-2">
                                <button v-if="c.conversaId" type="button" class="text-xs font-medium text-teal hover:underline" @click="verConversa(c.conversaId)">
                                    Ver conversa
                                </button>
                                <button v-if="status === 'aberto'" type="button" class="text-xs font-medium text-teal hover:underline" @click="abrirResposta(c)">
                                    Responder e resolver
                                </button>
                            </div>
                        </li>
                    </ul>
                </DarkCard>

                <DarkCard title="Uso do mês" :subtitle="`Teto de ${usd(uso.tetoMes)}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5"><path d="M4 18a8 8 0 1 1 16 0" stroke-linecap="round" /><path d="m12 18 4-5" stroke-linecap="round" /></svg>
                    </template>

                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="uso.perguntasMes.toLocaleString('pt-BR')" label="Perguntas" />
                        <KpiTile :value="usd(uso.custoMes)" label="Custo estimado" compact :tone="pctTeto >= 80 ? 'warn' : 'default'" />
                        <KpiTile :value="`${pctTeto}%`" label="Do teto" :tone="pctTeto >= 80 ? 'warn' : 'default'" />
                    </div>

                    <p class="mb-1 mt-4 text-xs font-semibold uppercase text-gray-400">Por usuário</p>
                    <ul class="max-h-48 overflow-y-auto text-sm">
                        <li v-for="u in uso.porUsuario" :key="u.nome" class="flex items-baseline justify-between gap-2 border-b border-gray-100 py-1">
                            <span class="truncate text-gray-700">{{ u.nome }}</span>
                            <span class="shrink-0 text-xs text-gray-500">
                                {{ u.perguntas }} perg. · {{ usd(u.custo) }}<span v-if="u.negativas" class="text-red-700"> · {{ u.negativas }} 👎</span>
                            </span>
                        </li>
                        <li v-if="!uso.porUsuario.length" class="text-gray-500">Ninguém usou ainda este mês.</li>
                    </ul>

                    <p class="mb-1 mt-4 text-xs font-semibold uppercase text-gray-400">Últimos 30 dias</p>
                    <ul class="max-h-48 overflow-y-auto text-sm">
                        <li v-for="d in uso.porDia" :key="d.dia" class="flex justify-between border-b border-gray-100 py-1 text-gray-600">
                            <span>{{ d.dia }}</span>
                            <span class="text-xs">{{ d.perguntas }} perg. · {{ usd(d.custo) }}</span>
                        </li>
                    </ul>

                    <p class="mb-1 mt-4 text-xs font-semibold uppercase text-gray-400">Respostas marcadas como ruins</p>
                    <ul class="max-h-48 overflow-y-auto text-sm">
                        <li v-for="(n, i) in uso.negativas" :key="i" class="border-b border-gray-100 py-1">
                            <button type="button" class="w-full text-left hover:text-teal" @click="verConversa(n.conversaId)">
                                <span class="block truncate text-gray-700">{{ n.titulo }}</span>
                                <span class="text-xs text-gray-400">{{ n.usuario }} · {{ n.quando }}</span>
                            </button>
                        </li>
                        <li v-if="!uso.negativas.length" class="text-gray-500">Nenhuma.</li>
                    </ul>
                </DarkCard>
            </div>
        </div>

        <ModalPadrao :show="respondendo !== null" titulo="Responder chamado" :subtitulo="respondendo ? `#${respondendo.id} · ${respondendo.usuario}` : ''" @close="respondendo = null">
            <form id="form-resposta-chamado" @submit.prevent="enviarResposta">
                <textarea v-model="form.resposta" rows="5" maxlength="2000" class="w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan" placeholder="A resposta chega a quem abriu o chamado pelo sino." />
                <p v-if="form.errors.resposta" class="mt-1 text-xs text-red-700">{{ form.errors.resposta }}</p>
            </form>
            <template #footer>
                <button type="submit" form="form-resposta-chamado" class="rounded bg-corp-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="form.processing || !form.resposta.trim()">
                    Responder e resolver
                </button>
            </template>
        </ModalPadrao>

        <ModalPadrao :show="conversa !== null" max-width="2xl" titulo="Conversa" :subtitulo="conversa ? `#${conversa.id} · ${conversa.usuario}` : ''" @close="conversa = null">
            <div v-if="conversa" class="max-h-[65vh] space-y-2 overflow-y-auto">
                <div v-for="m in conversa.mensagens" :key="m.id" class="rounded border px-3 py-2 text-sm" :class="m.papel === 'usuario' ? 'border-navy/30 bg-navy/5' : 'border-gray-200'">
                    <p class="mb-1 text-[0.7rem] text-gray-400">
                        {{ m.papel === 'usuario' ? 'Pergunta' : 'Resposta' }} · {{ m.quando }}
                        <template v-if="m.papel === 'ia'">
                            · {{ m.ferramentas.length ? m.ferramentas.join(', ') : 'sem consulta' }} · {{ usd(m.custo) }}
                            <template v-if="m.latenciaMs"> · {{ (m.latenciaMs / 1000).toFixed(1) }} s</template>
                            <span v-if="m.avaliacao === -1" class="text-red-700"> · 👎</span>
                            <span v-if="m.avaliacao === 1" class="text-green-700"> · 👍</span>
                        </template>
                    </p>
                    <p v-if="m.papel === 'usuario' || !m.html" class="whitespace-pre-wrap text-gray-700">{{ m.texto ?? m.erro }}</p>
                    <div v-else class="whitespace-pre-wrap text-gray-700">{{ m.texto }}</div>
                </div>
            </div>
        </ModalPadrao>
    </AuthenticatedLayout>
</template>
