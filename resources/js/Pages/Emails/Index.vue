<script setup>
/**
 * E-mails enviados — log do que saiu (ou falhou) pelo SMTP. Admin-only.
 * Mostra só o envelope; o corpo não é guardado (ver a migration de `emails_enviados`).
 */
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import KpiTile from '@/Components/KpiTile.vue';

const props = defineProps({
    emails: { type: Object, required: true },
    filtros: { type: Object, required: true },
    diasRetencao: { type: Number, default: 90 },
    redirecionamentos: { type: Object, default: () => ({}) },
    cota: { type: Object, required: true },
});

const status = ref(props.filtros.status || '');
const busca = ref(props.filtros.busca || '');

function aplicar() {
    router.get(route('emails.index'), { status: status.value || undefined, busca: busca.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(status, aplicar);

let debounce = null;
watch(busca, () => {
    clearTimeout(debounce);
    debounce = setTimeout(aplicar, 350);
});

const linhas = computed(() => props.emails.data ?? []);
const redirecionados = computed(() => Object.entries(props.redirecionamentos));

const fmt = (n) => Number(n).toLocaleString('pt-BR');

// O nível vem decidido do servidor (CotaDeEmails); aqui só a cor.
const TOM_COTA = { ok: 'ok', atencao: 'warn', esgotada: 'danger' };
const tomCota = computed(() => TOM_COTA[props.cota.nivel] ?? 'default');
const corBarra = computed(() => ({ ok: 'bg-emerald-500', atencao: 'bg-amber', esgotada: 'bg-red-500' })[props.cota.nivel]);
const larguraBarra = computed(() => `${Math.min(100, props.cota.percentual)}%`);
const projecaoEstoura = computed(() => props.cota.projecao !== null && props.cota.projecao > props.cota.limite);

const avisoCota = computed(() => {
    const c = props.cota;
    if (c.nivel === 'esgotada') {
        return `Cota do mês esgotada (${fmt(c.enviados)} de ${fmt(c.limite)}). O SMTP pode recusar os próximos envios até ${c.renovaEm} — confira os "Falharam" abaixo.`;
    }
    if (c.nivel === 'atencao' && c.percentual >= 80) {
        return `Já foram ${c.percentual.toLocaleString('pt-BR')}% da cota do mês. Restam ${fmt(c.restantes)} envios para ${c.diasRestantes} dia(s).`;
    }
    if (c.nivel === 'atencao') {
        return `No ritmo atual o mês fecha em ~${fmt(c.projecao)} envios, acima da cota de ${fmt(c.limite)}.`;
    }
    return null;
});
</script>

<template>
    <Head title="E-mails enviados" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="E-mails enviados">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <rect x="3.5" y="5.5" width="17" height="13" rx="1.5" />
                        <path d="m4 6.5 8 6 8-6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    Tudo que saiu pelo SMTP nos últimos {{ diasRetencao }} dias — destinatários, assunto e anexos.
                    O conteúdo do e-mail não é guardado.
                </template>
                <template #meta>
                    <StatusPill v-for="[nome, para] in redirecionados" :key="nome" tone="warn" surface="dark">
                        {{ nome }} redirecionado → {{ para }}
                    </StatusPill>
                </template>
                <template #filtros>
                    <div class="flex w-full flex-col gap-1 sm:w-auto sm:min-w-[260px]">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="busca"
                            type="search"
                            placeholder="Assunto ou destinatário"
                            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                        />
                    </div>
                    <FilterField v-model="status" label="Situação">
                        <option value="">Todos</option>
                        <option value="enviado">Enviados</option>
                        <option value="falhou">Falharam</option>
                    </FilterField>
                </template>
            </PageHero>

            <DarkCard
                class="mb-4"
                title="Cota do SMTP"
                :subtitle="`${cota.mes} · ${fmt(cota.limite)} envios por mês, renova em ${cota.renovaEm}`"
            >
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M4 18a8 8 0 1 1 16 0" stroke-linecap="round" />
                        <path d="m12 18 4-5" stroke-linecap="round" />
                    </svg>
                </template>
                <template #actions>
                    <StatusPill :tone="tomCota === 'default' ? 'neutral' : tomCota" surface="dark">
                        {{ cota.nivel === 'esgotada' ? 'Esgotada' : cota.nivel === 'atencao' ? 'Atenção' : 'Dentro da cota' }}
                    </StatusPill>
                </template>

                <div
                    v-if="avisoCota"
                    class="mb-3 rounded border px-3 py-2 text-sm"
                    :class="cota.nivel === 'esgotada' ? 'border-red-300 bg-red-50 text-red-700' : 'border-amber-300 bg-amber-50 text-amber-700'"
                    role="alert"
                >
                    {{ avisoCota }}
                </div>

                <div class="flex flex-wrap gap-2">
                    <KpiTile :value="`${fmt(cota.enviados)} / ${fmt(cota.limite)}`" label="Enviados no mês" :tone="tomCota" />
                    <KpiTile :value="`${cota.percentual.toLocaleString('pt-BR')}%`" label="Da cota usada" :tone="tomCota" />
                    <KpiTile :value="fmt(cota.restantes)" label="Restantes" />
                    <KpiTile
                        :value="cota.projecao === null ? '—' : `~${fmt(cota.projecao)}`"
                        label="Projeção do mês"
                        :tone="projecaoEstoura ? 'warn' : 'default'"
                    />
                </div>

                <div class="mt-3 h-2 w-full overflow-hidden rounded bg-gray-100" :title="`${cota.percentual}% da cota`">
                    <div class="h-full transition-all" :class="corBarra" :style="{ width: larguraBarra }" />
                </div>
                <p class="mt-2 text-[0.7rem] text-gray-500">
                    Conta cada mensagem aceita pelo SMTP (falhas não entram).
                    <template v-if="cota.projecao === null">A projeção aparece a partir do 5º dia do mês.</template>
                    E-mail enviado do ambiente de desenvolvimento usa a mesma conta e não aparece aqui.
                </p>
            </DarkCard>

            <DarkCard title="Envios" :subtitle="`${Number(emails.total).toLocaleString('pt-BR')} registro(s), do mais recente ao mais antigo`">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M4 12h16M4 6h16M4 18h10" stroke-linecap="round" />
                    </svg>
                </template>

                <p v-if="linhas.length === 0" class="px-4 py-10 text-center text-sm text-gray-500">
                    Nenhum e-mail registrado{{ filtros.status || filtros.busca ? ' com esses filtros' : '' }}.
                </p>

                <template v-else>
                    <div class="tbl-wrap">
                        <table class="tbl tbl-cartoes sm:min-w-[1000px]">
                            <thead>
                                <tr class="tbl-head-row">
                                    <th class="tbl-th">Quando</th>
                                    <th class="tbl-th">Situação</th>
                                    <th class="tbl-th">Assunto</th>
                                    <th class="tbl-th">Para</th>
                                    <th class="tbl-th">Cópia</th>
                                    <th class="tbl-th">Anexos</th>
                                    <th class="tbl-th">Origem</th>
                                </tr>
                            </thead>
                            <tbody class="tbl-body">
                                <tr v-for="e in linhas" :key="e.id" class="tbl-row">
                                    <td class="tbl-td tbl-td-titulo whitespace-nowrap">{{ e.quando }}</td>
                                    <td class="tbl-td" data-rotulo="Situação">
                                        <StatusPill :tone="e.status === 'enviado' ? 'ok' : 'danger'" size="sm">
                                            {{ e.status === 'enviado' ? 'Enviado' : 'Falhou' }}
                                        </StatusPill>
                                        <span v-if="e.erro" class="tbl-sub sm:mx-auto sm:max-w-[220px] sm:truncate" :title="e.erro">{{ e.erro }}</span>
                                    </td>
                                    <td class="tbl-td" data-rotulo="Assunto">
                                        <span class="tbl-main sm:max-w-[360px]" :title="e.assunto">{{ e.assunto || '—' }}</span>
                                        <span v-if="e.remetente" class="tbl-sub">de {{ e.remetente }}</span>
                                    </td>
                                    <td class="tbl-td sm:min-w-[200px] [overflow-wrap:anywhere]" data-rotulo="Para">{{ e.para || '—' }}</td>
                                    <td class="tbl-td sm:min-w-[200px] [overflow-wrap:anywhere]" data-rotulo="Cópia">
                                        {{ e.cc || '—' }}
                                        <span v-if="e.bcc" class="tbl-sub">cco: {{ e.bcc }}</span>
                                    </td>
                                    <td class="tbl-td" data-rotulo="Anexos">
                                        <span v-if="e.anexos.length" class="tbl-sub">{{ e.anexos.join(', ') }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="tbl-td" data-rotulo="Origem"><span class="tbl-sub">{{ e.origem || '—' }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pt-3">
                        <Pagination :meta="emails" :only="['emails']" />
                    </div>
                </template>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
