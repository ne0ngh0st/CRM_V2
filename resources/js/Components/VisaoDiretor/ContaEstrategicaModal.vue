<script setup>
/**
 * Cadastrar / editar uma conta-alvo e os VÍNCULOS dela com o CRM.
 *
 * O vínculo é o que torna tudo o mais derivável (lojas, status, atendimento,
 * faturamento), então é a metade importante do formulário. Vincular por GRUPO é o caso
 * comum — a ESTAPAR são 21 grupos no TOTVS, um por UF —, e por isso a busca mostra
 * quantas lojas cada grupo traria: vincular às cegas é como a conta vira "2.000 lojas"
 * por engano.
 *
 * Vínculo `sugestao` veio da carga da planilha (casamento por nome) e aparece marcado até
 * alguém confirmar. Salvar o formulário grava o que estiver na lista, com a origem de cada
 * um — confirmar é trocar a origem para `manual`.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatInteiro } from '@/utils/formato';
import HistoricoObservacaoConta from '@/Components/VisaoDiretor/HistoricoObservacaoConta.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** `null` = conta nova. */
    conta: { type: Object, default: null },
    segmentoInicial: { type: [Number, null], default: null },
    segmentos: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    segmento_id: null,
    nome: '',
    uf: '',
    filiais_mercado: '',
    site: '',
    observacao: '',
    vinculos: [],
});

watch(() => props.show, (aberto) => {
    if (! aberto) return;

    form.clearErrors();
    const c = props.conta;

    form.segmento_id = c?.segmentoId ?? props.segmentoInicial ?? props.segmentos[0]?.id ?? null;
    form.nome = c?.nome ?? '';
    form.uf = c?.uf ?? '';
    form.filiais_mercado = c?.filiaisMercado ?? '';
    form.site = c?.site ?? '';
    form.observacao = c?.observacao ?? '';
    form.vinculos = (c?.vinculos ?? []).map((v) => ({ ...v }));

    termo.value = '';
    resultados.value = { grupos: [], clientes: [] };
});

const titulo = computed(() => (props.conta ? `Editar ${props.conta.nome}` : 'Nova conta-alvo'));
const sugestoes = computed(() => form.vinculos.filter((v) => v.origem === 'sugestao').length);

function jaVinculado(tipo, codigo) {
    return form.vinculos.some((v) => v.tipo === tipo && v.codigo === codigo);
}

function adicionar(tipo, item) {
    if (jaVinculado(tipo, item.codigo)) return;
    form.vinculos.push({ tipo, codigo: item.codigo, nome: item.nome, origem: 'manual' });
}

function remover(indice) {
    form.vinculos.splice(indice, 1);
}

function confirmar(vinculo) {
    vinculo.origem = 'manual';
}

function confirmarTodas() {
    form.vinculos.forEach((v) => { v.origem = 'manual'; });
}

// ─── Busca de grupo/cliente ──────────────────────────────────────────────────
const termo = ref('');
const buscando = ref(false);
const resultados = ref({ grupos: [], clientes: [] });
let atraso;
let ultimaBusca = 0;

function onBusca() {
    clearTimeout(atraso);
    atraso = setTimeout(buscar, 300);
}

async function buscar() {
    const q = termo.value.trim();

    if (q.length < 3) {
        resultados.value = { grupos: [], clientes: [] };

        return;
    }

    // Resposta atrasada de uma busca antiga não pode sobrescrever a atual.
    const esta = ++ultimaBusca;
    buscando.value = true;

    try {
        const resposta = await fetch(route('visao-diretor.maiores.busca-vinculo', { q }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (esta === ultimaBusca && resposta.ok) {
            resultados.value = await resposta.json();
        }
    } finally {
        if (esta === ultimaBusca) buscando.value = false;
    }
}

function salvar() {
    const opcoes = { preserveScroll: true, onSuccess: () => emit('close') };
    const dados = (d) => ({
        ...d,
        vinculos: d.vinculos.map(({ tipo, codigo, origem }) => ({ tipo, codigo, origem })),
    });

    if (props.conta) {
        form.transform(dados).patch(route('visao-diretor.maiores.update', props.conta.id), opcoes);
    } else {
        form.transform(dados).post(route('visao-diretor.maiores.store'), opcoes);
    }
}

const erroVinculos = computed(() => Object.entries(form.errors)
    .filter(([campo]) => campo.startsWith('vinculos'))
    .map(([, msg]) => msg)
    .join(' '));

const campo = 'mt-1 block w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan';
const rotulo = 'text-xs font-semibold uppercase tracking-wide text-gray-400';
</script>

<template>
    <ModalPadrao :show="show" :titulo="titulo" subtitulo="Rede do mercado e como ela aparece no CRM" max-width="2xl" @close="emit('close')">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                <path d="M11 4.2a8 8 0 1 0 8.8 8.8H11Z" stroke-linejoin="round" />
                <path d="M14 3.2a7 7 0 0 1 6.8 6.8H14Z" stroke-linejoin="round" />
            </svg>
        </template>

        <form id="form-conta-estrategica" class="grid gap-3 sm:grid-cols-6" @submit.prevent="salvar">
            <div class="sm:col-span-3">
                <label :class="rotulo" for="conta_nome">Nome da rede</label>
                <input id="conta_nome" v-model="form.nome" type="text" required maxlength="150" :class="campo" />
                <InputError :message="form.errors.nome" class="mt-1" />
            </div>
            <div class="sm:col-span-3">
                <label :class="rotulo" for="conta_segmento">Segmento</label>
                <select id="conta_segmento" v-model="form.segmento_id" required :class="campo">
                    <option v-for="s in segmentos" :key="s.id" :value="s.id">{{ s.nome }}</option>
                </select>
                <InputError :message="form.errors.segmento_id" class="mt-1" />
            </div>
            <div class="sm:col-span-1">
                <label :class="rotulo" for="conta_uf">UF</label>
                <input id="conta_uf" v-model="form.uf" type="text" maxlength="2" :class="[campo, 'uppercase']" />
                <InputError :message="form.errors.uf" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
                <label :class="rotulo" for="conta_filiais">Filiais no mercado</label>
                <input id="conta_filiais" v-model="form.filiais_mercado" type="number" min="0" :class="campo" />
                <InputError :message="form.errors.filiais_mercado" class="mt-1" />
            </div>
            <div class="sm:col-span-3">
                <label :class="rotulo" for="conta_site">Site</label>
                <input id="conta_site" v-model="form.site" type="text" maxlength="150" placeholder="exemplo.com.br" :class="campo" />
                <InputError :message="form.errors.site" class="mt-1" />
            </div>
            <div class="sm:col-span-6">
                <label :class="rotulo" for="conta_obs">Observação estratégica</label>
                <textarea id="conta_obs" v-model="form.observacao" rows="2" maxlength="5000" :class="campo" />
                <InputError :message="form.errors.observacao" class="mt-1" />
                <p class="mt-1 text-[0.7rem] text-gray-400">Ao salvar um texto diferente, a versão anterior fica no histórico abaixo.</p>
            </div>
            <div v-if="conta && show" class="sm:col-span-6">
                <p :class="rotulo">Histórico da observação</p>
                <HistoricoObservacaoConta
                    class="mt-1"
                    :conta-id="conta.id"
                    :versao="conta.versoesObservacao"
                    altura-maxima="max-h-40"
                />
            </div>

            <!-- Vínculos -->
            <div class="border-t border-gray-200 pt-3 sm:col-span-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p :class="rotulo">Vínculos com o CRM</p>
                    <button
                        v-if="sugestoes"
                        type="button"
                        class="text-xs font-semibold text-amber-dark hover:underline"
                        @click="confirmarTodas"
                    >Confirmar {{ sugestoes }} sugestão(ões)</button>
                </div>

                <p v-if="! form.vinculos.length" class="mt-2 rounded border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400">
                    Sem vínculo, a conta aparece como <strong>Lead</strong>: nenhuma loja nossa.
                </p>
                <ul v-else class="mt-2 flex flex-wrap gap-1.5">
                    <li
                        v-for="(v, i) in form.vinculos"
                        :key="`${v.tipo}:${v.codigo}`"
                        class="inline-flex items-center gap-1 rounded border px-2 py-1 text-xs"
                        :class="v.origem === 'sugestao' ? 'border-amber bg-amber/10 text-gray-700' : 'border-gray-300 bg-gray-50 text-gray-700'"
                    >
                        <span class="text-[0.65rem] font-semibold uppercase text-gray-400">{{ v.tipo === 'grupo' ? 'Grupo' : 'Cliente' }}</span>
                        <span class="max-w-[14rem] truncate" :title="v.nome || v.codigo">{{ v.nome || v.codigo }}</span>
                        <span class="text-gray-400">({{ v.codigo }})</span>
                        <button
                            v-if="v.origem === 'sugestao'"
                            type="button"
                            class="ml-0.5 font-semibold text-green-700 hover:text-green-900"
                            title="Confirmar sugestão"
                            @click="confirmar(v)"
                        >✓</button>
                        <button type="button" class="ml-0.5 text-gray-400 hover:text-red-700" title="Remover vínculo" @click="remover(i)">✕</button>
                    </li>
                </ul>
                <InputError :message="erroVinculos" class="mt-1" />

                <label :class="[rotulo, 'mt-3 block']" for="conta_busca">Adicionar grupo ou cliente</label>
                <input
                    id="conta_busca"
                    v-model="termo"
                    type="search"
                    placeholder="Nome do grupo, razão social, CNPJ ou código (mín. 3 letras)"
                    :class="campo"
                    @input="onBusca"
                    @keydown.enter.prevent
                />

                <div v-if="termo.trim().length >= 3" class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Grupos do TOTVS</p>
                        <p v-if="buscando" class="mt-1 text-xs text-gray-400">Buscando…</p>
                        <p v-else-if="! resultados.grupos.length" class="mt-1 text-xs text-gray-400">Nenhum grupo.</p>
                        <ul v-else class="mt-1 max-h-48 divide-y divide-gray-100 overflow-y-auto rounded border border-gray-200">
                            <li v-for="g in resultados.grupos" :key="g.codigo" class="flex items-center justify-between gap-2 px-2 py-1.5 text-xs">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-gray-800">{{ g.nome }}</span>
                                    <span class="text-gray-400">{{ g.codigo }} · {{ formatInteiro(g.lojas) }} loja{{ g.lojas !== 1 ? 's' : '' }}</span>
                                </span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded border border-teal px-2 py-0.5 font-semibold text-teal hover:bg-teal/10 disabled:cursor-default disabled:border-gray-200 disabled:text-gray-300 disabled:hover:bg-transparent"
                                    :disabled="jaVinculado('grupo', g.codigo)"
                                    @click="adicionar('grupo', g)"
                                >{{ jaVinculado('grupo', g.codigo) ? 'Vinculado' : '+ Vincular' }}</button>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Clientes</p>
                        <p v-if="buscando" class="mt-1 text-xs text-gray-400">Buscando…</p>
                        <p v-else-if="! resultados.clientes.length" class="mt-1 text-xs text-gray-400">Nenhum cliente.</p>
                        <ul v-else class="mt-1 max-h-48 divide-y divide-gray-100 overflow-y-auto rounded border border-gray-200">
                            <li v-for="c in resultados.clientes" :key="c.codigo" class="flex items-center justify-between gap-2 px-2 py-1.5 text-xs">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-gray-800" :title="c.razaoSocial">{{ c.nome }}</span>
                                    <span class="text-gray-400">{{ c.codigo }}<template v-if="c.responsaveis.length"> · {{ c.responsaveis.join(', ') }}</template></span>
                                </span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded border border-teal px-2 py-0.5 font-semibold text-teal hover:bg-teal/10 disabled:cursor-default disabled:border-gray-200 disabled:text-gray-300 disabled:hover:bg-transparent"
                                    :disabled="jaVinculado('cliente', c.codigo)"
                                    @click="adicionar('cliente', c)"
                                >{{ jaVinculado('cliente', c.codigo) ? 'Vinculado' : '+ Vincular' }}</button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <SecondaryButton type="button" @click="emit('close')">Cancelar</SecondaryButton>
            <PrimaryButton type="submit" form="form-conta-estrategica" :disabled="form.processing">
                {{ form.processing ? 'Salvando…' : 'Salvar' }}
            </PrimaryButton>
        </template>
    </ModalPadrao>
</template>
