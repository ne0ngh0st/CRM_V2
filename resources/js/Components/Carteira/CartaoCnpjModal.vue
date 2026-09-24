<script setup>
import { computed, ref, watch } from 'vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import StatusPill from '@/Components/StatusPill.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/*
 * "Verificar cartão CNPJ": o cartão da Receita da filial, lado a lado com o que o
 * TOTVS tem. O modal abre na hora e busca por baixo — a consulta é externa, e a tela
 * não pode esperar por ela (Regra de ouro nº 9).
 *
 * Quem normaliza, formata e compara é o servidor (`CartaoCnpjService`); aqui só se
 * desenha. Não trazer regra de divergência nem mapa de provedor para cá.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // { id, razaoSocial, cnpj } — a linha da Carteira ou a filial.
    cliente: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const carregando = ref(false);
const erro = ref('');
const dados = ref(null);
const mostrarSecundarios = ref(false);

const FONTES = { brasilapi: 'BrasilAPI', minhareceita: 'Minha Receita', cnpja: 'CNPJá' };

// ATIVA é a única situação em que a empresa pode comprar; o resto pede atenção, e
// BAIXADA/NULA significam que ela deixou de existir.
const TOM_SITUACAO = { ATIVA: 'ok', SUSPENSA: 'warn', INAPTA: 'warn', BAIXADA: 'danger', NULA: 'danger' };

// Rótulo de campo: mesma gramática micro-maiúscula dos filtros do PageHero.
const ROTULO = 'mb-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400';

const cartao = computed(() => dados.value?.cartao ?? null);

async function carregar(atualizar = false) {
    if (! props.cliente) return;

    carregando.value = true;
    erro.value = '';

    try {
        const url = route('carteira.cartaoCnpj', props.cliente.id) + (atualizar ? '?atualizar=1' : '');
        const resposta = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        const corpo = await resposta.json().catch(() => ({}));

        if (resposta.status === 429) {
            erro.value = 'Muitas consultas seguidas. Espere um minuto e tente de novo.';
        } else if (! resposta.ok) {
            erro.value = corpo.erro ?? 'Não foi possível consultar a Receita agora.';
        } else {
            dados.value = corpo;
        }
    } catch {
        erro.value = 'Não foi possível consultar a Receita agora.';
    } finally {
        carregando.value = false;
    }
}

watch(() => props.show, (aberto) => {
    if (! aberto) return;
    dados.value = null;
    mostrarSecundarios.value = false;
    carregar();
});

function sim(valor) {
    if (valor === true) return 'Sim';
    if (valor === false) return 'Não';
    return '—';
}

const moeda = (v) => (v == null ? '—' : v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }));

const enderecoLinha1 = computed(() => {
    const e = cartao.value?.endereco;
    if (! e) return '';
    return [e.logradouro, e.numero].filter(Boolean).join(', ') + (e.complemento ? ` — ${e.complemento}` : '');
});

const enderecoLinha2 = computed(() => {
    const e = cartao.value?.endereco;
    if (! e) return '';
    return [e.bairro, [e.municipio, e.uf].filter(Boolean).join('/'), e.cep].filter(Boolean).join(' · ');
});

const consultadoTexto = computed(() => {
    const d = dados.value;
    if (! d) return '';
    const quando = d.diasDesdeConsulta === 0 ? 'hoje' : d.diasDesdeConsulta === 1 ? 'ontem' : `há ${d.diasDesdeConsulta} dias`;
    return `Consultado ${quando} (${d.consultadoEm}) via ${FONTES[d.fonte] ?? d.fonte}`;
});
</script>

<template>
    <ModalPadrao
        :show="show"
        titulo="Cartão CNPJ"
        :subtitulo="cliente ? `${cliente.razaoSocial ?? ''} · ${cliente.cnpj ?? ''}` : ''"
        max-width="2xl"
        @close="emit('close')"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                <rect x="3" y="5" width="18" height="14" rx="1.5" />
                <circle cx="8.5" cy="11" r="2" />
                <path d="M5.5 16c.6-1.5 1.7-2.2 3-2.2s2.4.7 3 2.2M14 10h4.5M14 13.5h3" stroke-linecap="round" />
            </svg>
        </template>

        <p v-if="carregando && ! cartao" class="py-8 text-center text-sm text-gray-500">Consultando a Receita…</p>

        <p v-else-if="erro && ! cartao" class="rounded border border-amber/40 bg-amber/10 px-3 py-2 text-sm text-gray-700">{{ erro }}</p>

        <div v-else-if="cartao" class="space-y-4 text-sm text-gray-700">
            <!-- Identificação + situação: o que o vendedor precisa ver sem rolar. -->
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-semibold leading-5 text-gray-900">{{ cartao.razaoSocial }}</p>
                    <p v-if="cartao.nomeFantasia" class="text-xs text-gray-500">{{ cartao.nomeFantasia }}</p>
                    <p class="mt-0.5 text-xs text-gray-400">
                        {{ cartao.cnpjFormatado }}<template v-if="cartao.matriz !== null"> · {{ cartao.matriz ? 'Matriz' : 'Filial' }}</template>
                    </p>
                </div>
                <div class="shrink-0 sm:text-right">
                    <StatusPill :tone="TOM_SITUACAO[cartao.situacao] ?? 'neutral'">{{ cartao.situacao }}</StatusPill>
                    <p v-if="cartao.dataSituacao" class="mt-1 text-xs text-gray-400">desde {{ cartao.dataSituacao }}</p>
                    <p v-if="cartao.motivoSituacao" class="text-xs text-gray-500">{{ cartao.motivoSituacao }}</p>
                </div>
            </div>

            <div v-if="dados.desatualizado" class="rounded border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-gray-700">
                A Receita não respondeu agora — este é o último cartão consultado ({{ dados.consultadoEm }}).
            </div>
            <div v-if="erro" class="rounded border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-gray-700">{{ erro }}</div>

            <!-- O que o time de Cadastro precisa corrigir no TOTVS. -->
            <div v-if="dados.divergencias.length" class="rounded border border-amber/40 bg-amber/5">
                <p class="border-b border-amber/30 px-3 py-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-amber-dark">
                    Cadastro do TOTVS diferente da Receita
                </p>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-amber/20">
                        <tr v-for="d in dados.divergencias" :key="d.campo">
                            <td class="w-28 px-3 py-1.5 font-medium text-gray-500">{{ d.campo }}</td>
                            <td class="px-3 py-1.5"><span class="text-gray-400">TOTVS:</span> {{ d.totvs }}</td>
                            <td class="px-3 py-1.5"><span class="text-gray-400">Receita:</span> <span class="font-medium text-gray-900">{{ d.receita }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                <div>
                    <dt :class="ROTULO">Abertura</dt>
                    <dd>{{ cartao.dataAbertura ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="ROTULO">Porte</dt>
                    <dd>{{ cartao.porte ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="ROTULO">Capital social</dt>
                    <dd>{{ moeda(cartao.capitalSocial) }}</dd>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <dt :class="ROTULO">Natureza jurídica</dt>
                    <dd>{{ cartao.naturezaJuridica ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="ROTULO">Simples Nacional</dt>
                    <dd>{{ sim(cartao.simples) }}</dd>
                </div>
                <div>
                    <dt :class="ROTULO">MEI</dt>
                    <dd>{{ sim(cartao.mei) }}</dd>
                </div>
            </dl>

            <div>
                <p :class="ROTULO">Atividade principal</p>
                <p v-if="cartao.cnaePrincipal">
                    <span class="text-gray-400">{{ cartao.cnaePrincipal.codigo }}</span> {{ cartao.cnaePrincipal.descricao }}
                </p>
                <p v-else>—</p>
                <template v-if="cartao.cnaesSecundarios.length">
                    <button type="button" class="mt-1 text-xs font-medium text-teal hover:underline" @click="mostrarSecundarios = ! mostrarSecundarios">
                        {{ mostrarSecundarios ? 'Ocultar' : 'Ver' }} {{ cartao.cnaesSecundarios.length }}
                        {{ cartao.cnaesSecundarios.length === 1 ? 'atividade secundária' : 'atividades secundárias' }}
                    </button>
                    <ul v-if="mostrarSecundarios" class="mt-1 max-h-40 space-y-0.5 overflow-y-auto text-xs">
                        <li v-for="c in cartao.cnaesSecundarios" :key="c.codigo">
                            <span class="text-gray-400">{{ c.codigo }}</span> {{ c.descricao }}
                        </li>
                    </ul>
                </template>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <p :class="ROTULO">Endereço na Receita</p>
                    <p>{{ enderecoLinha1 || '—' }}</p>
                    <p class="text-xs text-gray-500">{{ enderecoLinha2 }}</p>
                </div>
                <div>
                    <p :class="ROTULO">Contato na Receita</p>
                    <p v-for="t in cartao.telefones" :key="t">{{ t }}</p>
                    <p v-if="cartao.email" class="break-all">{{ cartao.email }}</p>
                    <p v-if="! cartao.telefones.length && ! cartao.email">—</p>
                </div>
            </div>

            <p class="text-[0.65rem] leading-4 text-gray-400">
                {{ consultadoTexto }}. A Receita publica a base uma vez por mês, então mudanças recentes podem levar 30 a 45 dias para aparecer.
            </p>
        </div>

        <template #footer>
            <SecondaryButton type="button" @click="emit('close')">Fechar</SecondaryButton>
            <PrimaryButton v-if="cartao" type="button" :disabled="carregando" @click="carregar(true)">
                {{ carregando ? 'Consultando…' : 'Atualizar da Receita' }}
            </PrimaryButton>
        </template>
    </ModalPadrao>
</template>

