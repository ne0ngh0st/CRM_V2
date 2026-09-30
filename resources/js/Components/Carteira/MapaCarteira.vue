<script>
/*
 * A geografia é estática e igual para todo mundo: contorno das UFs e das mesorregiões.
 * Fica em `public/geo/`, o navegador guarda em cache, e o servidor só manda CONTAGEM por
 * código IBGE.
 *
 * ⚠️ `<script>` normal, não `<script setup>`: as promessas têm que ser uma por página,
 * não uma por instância do componente (mesmo motivo da `pilha` do `Modal.vue`).
 */
function buscarJson(url) {
    return fetch(url).then((r) => {
        if (! r.ok) throw new Error(`${url}: HTTP ${r.status}`);

        return r.json();
    });
}

let malhaUfs = null;

function carregarUfs() {
    malhaUfs ??= buscarJson('/geo/ufs.json').catch((e) => {
        malhaUfs = null; // deixa tentar de novo na próxima abertura da aba

        throw e;
    });

    return malhaUfs;
}

/*
 * Um arquivo de mesorregiões POR ESTADO, baixado só quando alguém entra nele. Na
 * qualidade "intermediária" do IBGE (bordas precisas, pedido do Tony) o Brasil inteiro
 * dava 2,4 MB; por estado o maior é MG, com 75 KB comprimido, e SP tem 49 KB.
 */
const malhasMesos = {};

function carregarMesorregioes(uf) {
    malhasMesos[uf] ??= buscarJson(`/geo/mesorregioes/${uf}.json`).catch((e) => {
        delete malhasMesos[uf];

        throw e;
    });

    return malhasMesos[uf];
}

// `codarea` do GeoJSON do IBGE (= os dois primeiros dígitos do código do município).
const UF_POR_CODIGO = {
    11: 'RO', 12: 'AC', 13: 'AM', 14: 'RR', 15: 'PA', 16: 'AP', 17: 'TO', 21: 'MA', 22: 'PI',
    23: 'CE', 24: 'RN', 25: 'PB', 26: 'PE', 27: 'AL', 28: 'SE', 29: 'BA', 31: 'MG', 32: 'ES',
    33: 'RJ', 35: 'SP', 41: 'PR', 42: 'SC', 43: 'RS', 50: 'MS', 51: 'MT', 52: 'GO', 53: 'DF',
};

/*
 * Nome e posição do SELO de cada estado. A posição é escrita à mão, e não o centro do
 * polígono: o centro de GO cai em cima do DF, o do RJ no mar, e os estados pequenos do
 * Nordeste empilhariam os selos uns sobre os outros.
 *
 * ⚠️ RN, PB, PE, AL e SE ficam NO MAR, em coluna, de propósito: os cinco cabem em 600 km
 * de litoral e qualquer posição em terra põe um selo em cima do outro (visto no
 * navegador). ES e RJ também saem para a costa, pelo mesmo motivo.
 */
const UFS = {
    AC: ['Acre', -9.1, -70.5], AL: ['Alagoas', -10.4, -32.6], AM: ['Amazonas', -4.2, -64.5],
    AP: ['Amapá', 1.4, -51.8], BA: ['Bahia', -12.6, -41.9], CE: ['Ceará', -4.6, -39.8],
    DF: ['Distrito Federal', -14.4, -46.4], ES: ['Espírito Santo', -19.9, -37.0],
    GO: ['Goiás', -17.2, -51.4], MA: ['Maranhão', -4.6, -45.6], MG: ['Minas Gerais', -18.9, -44.6],
    MS: ['Mato Grosso do Sul', -20.5, -54.8], MT: ['Mato Grosso', -12.9, -55.9],
    PA: ['Pará', -4.5, -52.8], PB: ['Paraíba', -6.2, -32.6], PE: ['Pernambuco', -8.3, -32.6],
    PI: ['Piauí', -8.0, -43.2], PR: ['Paraná', -24.9, -51.6], RJ: ['Rio de Janeiro', -23.4, -39.6],
    RN: ['Rio Grande do Norte', -4.1, -32.6], RO: ['Rondônia', -10.9, -63.0],
    RR: ['Roraima', 2.0, -61.4], RS: ['Rio Grande do Sul', -29.8, -53.3],
    SC: ['Santa Catarina', -27.4, -50.3], SE: ['Sergipe', -12.5, -32.6],
    SP: ['São Paulo', -22.0, -48.9], TO: ['Tocantins', -10.4, -48.3],
};
</script>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import { ROTULOS_STATUS_CARTEIRA } from '@/constants/carteira';

/*
 * Aba Mapa da Carteira: a MESMA lista, vista por onde os endereços estão.
 *
 * DOIS NÍVEIS (feedback do Tony em 2026-09-30, depois de três versões):
 *
 *   1. BRASIL — estados pintados pela quantidade de clientes, com pill "SP 21.834".
 *      Clicar entra no estado.
 *   2. ESTADO — dividido nas mesorregiões do IBGE, pintadas e com pill de nome e
 *      número. Clicar numa região ABRE A LISTA dos clientes dela (`?mesorregiao=`).
 *
 * Não há nível de cidade: a versão com bolhas por cidade, mesmo com zoom por região,
 * foi descartada — a região já é o recorte que o vendedor procura, e a lista aberta por
 * ela tem cidade por cidade na coluna "Cidade / UF".
 *
 * A lista ao lado acompanha o nível (estados → regiões) e faz o mesmo que o mapa.
 *
 * O mapa não decide quem está na lista (isso é do servidor, pela mesma `baseQuery()` da
 * tabela); aqui só se desenha. A regra que a tela tem que respeitar: o número de uma
 * pill é o total da lista que o clique nela abre, e nenhum total é soma de outro (cliente
 * com lojas em duas regiões está nas duas e uma vez no estado).
 */
const props = defineProps({
    // { estados, mesorregioes, semLocalizacao, totais, … } — ver MapaDaCarteira.php
    mapa: { type: Object, default: null },
    // Status aplicado na lista ('' = nenhum): com ele, o mapa assume a cor do status.
    status: { type: String, default: '' },
    // Região filtrada na lista: o mapa reabre no estado dela, com ela destacada.
    regiaoAtiva: { type: Number, default: null },
});

const emit = defineEmits(['abrir-regiao', 'abrir-estado']);

// Mesmos tons do StatusPill (green/amber/red 600) e o navy da marca quando não há status.
const COR_STATUS = { ativo: '#16a34a', inativando: '#d97706', inativo: '#dc2626' };
const COR_PADRAO = '#0F3A69';
const COR_DESTAQUE = '#ff8f00';

const el = ref(null);
const erro = ref('');
const pronto = ref(false);
const carregandoRegioes = ref(false);
const ufAtiva = ref(null);

// Objetos do Leaflet fora da reatividade profunda: o Vue não precisa observar o mapa.
const leaflet = shallowRef(null);
const instancia = shallowRef(null);
let camadaUfs = null;
let camadaMesos = null;
let camadaSelos = null;
// As feições de UF, guardadas para ligar/desligar o tooltip conforme o nível.
const layersUf = [];

const cor = computed(() => COR_STATUS[props.status] ?? COR_PADRAO);
const fmt = (n) => (n ?? 0).toLocaleString('pt-BR');
const plural = (n, um, varios) => `${fmt(n)} ${n === 1 ? um : varios}`;
const nomeUf = (uf) => UFS[uf]?.[0] ?? uf;
const ufDaMeso = (cod) => UF_POR_CODIGO[Math.floor(cod / 100)] ?? null;

const estados = computed(() => [...(props.mapa?.estados ?? [])].sort((a, b) => b.clientes - a.clientes));
const estadoAtivo = computed(() => estados.value.find((e) => e.uf === ufAtiva.value) ?? null);
const maiorEstado = computed(() => Math.max(1, ...estados.value.map((e) => e.clientes)));

const regioes = computed(() => (props.mapa?.mesorregioes ?? [])
    .filter((m) => ufDaMeso(m.cod) === ufAtiva.value)
    .sort((a, b) => b.clientes - a.clientes));
const maiorRegiao = computed(() => Math.max(1, ...regioes.value.map((m) => m.clientes)));

const resumo = computed(() => {
    const t = props.mapa?.totais;
    if (! t) return 'Carregando…';

    return [
        plural(t.clientes, 'cliente', 'clientes'),
        plural(t.comerciais, 'filial', 'filiais'),
        t.entregas ? plural(t.entregas, 'ponto de entrega', 'pontos de entrega') : null,
    ].filter(Boolean).join(' · ');
});

const semLocalizacao = computed(() => {
    const s = props.mapa?.semLocalizacao;

    return s ? s.comerciais + s.entregas : 0;
});

function numeros(b) {
    return [
        plural(b.comerciais, 'filial', 'filiais'),
        b.entregas ? `+${plural(b.entregas, 'entrega', 'entregas')}` : null,
    ].filter(Boolean).join(' · ');
}

// "Ativo: 23", e não "23 ativo": o rótulo é o mesmo da pill da lista, sem flexionar.
function quebraPorStatus(b) {
    return [
        b.ativos ? `${ROTULOS_STATUS_CARTEIRA.ativo}: ${fmt(b.ativos)}` : null,
        b.inativando ? `${ROTULOS_STATUS_CARTEIRA.inativando}: ${fmt(b.inativando)}` : null,
        b.inativos ? `${ROTULOS_STATUS_CARTEIRA.inativo}: ${fmt(b.inativos)}` : null,
    ].filter(Boolean).join(' · ');
}

const ufDaFeicao = (f) => UF_POR_CODIGO[Number(f.properties?.codarea)] ?? null;

function limparCamada(camada) {
    camada?.remove();

    return null;
}

// Intensidade do coroplético: diz onde a carteira está antes de qualquer número.
const intensidade = (valor, maior) => 0.12 + 0.55 * Math.sqrt(valor / maior);

function pill(titulo, numero, classe = '') {
    const span = document.createElement('span');
    span.className = `mapa-selo ${classe}`;
    span.append(Object.assign(document.createElement('b'), { textContent: titulo }), numero);

    return leaflet.value.divIcon({ html: span, className: 'mapa-selo-icone', iconSize: null });
}

/*
 * O tooltip da UF só existe no nível Brasil. Sem isto, passar o mouse pelo estado VIZINHO
 * (cinza) dentro do nível estado abria um tooltip "Mato Grosso do Sul — 454 clientes" por
 * cima do estado aberto. `unbindTooltip` também fecha o que estiver aberto.
 */
function tooltipsUf(ativar) {
    layersUf.forEach((l) => {
        l.unbindTooltip();

        if (ativar) {
            l.bindTooltip(() => {
                const e = estados.value.find((x) => x.uf === l._uf);

                return e ? `${nomeUf(l._uf)} — ${plural(e.clientes, 'cliente', 'clientes')}` : `${nomeUf(l._uf)} — sem clientes neste recorte`;
            }, { sticky: true, direction: 'top' });
        }
    });
}

/* ── Nível 1: Brasil ─────────────────────────────────────────────────────────────── */

function estiloDoEstado(feicao) {
    const uf = ufDaFeicao(feicao);

    if (ufAtiva.value) {
        const ativa = uf === ufAtiva.value;

        return {
            color: ativa ? COR_PADRAO : '#d1d5db',
            weight: ativa ? 1.5 : 1,
            fillColor: ativa ? '#ffffff' : '#e5e7eb',
            fillOpacity: 1,
        };
    }

    const e = estados.value.find((x) => x.uf === uf);

    return {
        color: '#9ca3af',
        weight: 1,
        fillColor: e ? cor.value : '#ffffff',
        fillOpacity: e ? intensidade(e.clientes, maiorEstado.value) : 1,
    };
}

function desenharBrasil() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaMesos = limparCamada(camadaMesos);
    camadaSelos = limparCamada(camadaSelos);

    tooltipsUf(true);
    camadaUfs.setStyle(estiloDoEstado);

    camadaSelos = L.layerGroup();

    estados.value.forEach((e) => {
        const posicao = UFS[e.uf];
        if (! posicao) return;

        L.marker([posicao[1], posicao[2]], {
            icon: pill(e.uf, fmt(e.clientes)),
            title: `${posicao[0]} — ${plural(e.clientes, 'cliente', 'clientes')}`,
            keyboard: false,
        }).on('click', () => entrar(e.uf)).addTo(camadaSelos);
    });

    camadaSelos.addTo(mapa);
    mapa.fitBounds(camadaUfs.getBounds(), { padding: [8, 8] });
}

/* ── Nível 2: estado, dividido em regiões ────────────────────────────────────────── */

function estiloDaRegiao(feicao, emFoco = false) {
    const cod = Number(feicao.properties.codarea);
    const m = regioes.value.find((x) => x.cod === cod);
    const ativa = cod === props.regiaoAtiva;

    return {
        // Fronteira branca e larga: é ela que dá a leitura de "divisão" do estado.
        color: ativa ? COR_DESTAQUE : emFoco ? COR_PADRAO : '#ffffff',
        weight: ativa ? 3 : emFoco ? 2.5 : 1.75,
        fillColor: m ? cor.value : '#f3f4f6',
        fillOpacity: m ? intensidade(m.clientes, maiorRegiao.value) + (emFoco ? 0.12 : 0) : 1,
    };
}

function abrirRegiao(cod) {
    if (regioes.value.some((m) => m.cod === cod)) emit('abrir-regiao', cod);
}

/*
 * Pills sem sobreposição. Da região com mais clientes para a com menos, cada pill tenta
 * VÁRIAS posições em volta do centro da região (centro, acima, abaixo, dos lados) antes
 * de desistir; só então tenta a versão curta, só com o número. A primeira versão tentava
 * uma posição só e em SP deixava "Macro Metropolitana" e "Piracicaba" sem pill, e
 * "Ribeirão Preto" só com "715".
 *
 * A caixa de colisão é INFLADA com folga: estimar a largura justa deixa duas pills
 * vizinhas passarem e encostarem no DOM.
 */
const colide = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

function caixa(ponto, texto) {
    const w = texto.length * 6.6 + 22;
    const h = 24;

    return { x: ponto.x - w / 2, y: ponto.y - h / 2, w, h };
}

const DESLOCAMENTOS = [[0, 0], [0, -22], [0, 22], [-40, 0], [40, 0], [-30, -20], [30, 20], [30, -20], [-30, 20], [0, -40], [0, 40]];

function posicionar(centro, texto, caixas) {
    const mapa = instancia.value;
    const base = mapa.latLngToContainerPoint(centro);

    for (const [dx, dy] of DESLOCAMENTOS) {
        const ponto = { x: base.x + dx, y: base.y + dy };
        const c = caixa(ponto, texto);

        if (! caixas.some((b) => colide(c, b))) {
            caixas.push(c);

            return mapa.containerPointToLatLng([ponto.x, ponto.y]);
        }
    }

    return null;
}

async function desenharEstado() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaMesos = limparCamada(camadaMesos);
    camadaSelos = limparCamada(camadaSelos);
    tooltipsUf(false);
    camadaUfs.setStyle(estiloDoEstado);

    let limites = null;
    camadaUfs.eachLayer((l) => {
        if (ufDaFeicao(l.feature) === ufAtiva.value) limites = l.getBounds();
    });
    if (limites) mapa.fitBounds(limites, { padding: [20, 20], animate: false });

    const uf = ufAtiva.value;
    let malha;

    carregandoRegioes.value = true;
    try {
        malha = await carregarMesorregioes(uf);
    } finally {
        carregandoRegioes.value = false;
    }

    // A pessoa pode ter trocado de nível enquanto a malha chegava.
    if (ufAtiva.value !== uf) return;

    camadaMesos = limparCamada(camadaMesos);
    camadaMesos = L.geoJSON(
        malha,
        {
            style: (f) => estiloDaRegiao(f),
            onEachFeature: (feicao, camada) => {
                const cod = Number(feicao.properties.codarea);

                camada.on('click', () => abrirRegiao(cod));
                // Realce no hover: é o que diz "isto é clicável" num mapa de áreas.
                camada.on('mouseover', () => camada.setStyle(estiloDaRegiao(feicao, true)));
                camada.on('mouseout', () => camada.setStyle(estiloDaRegiao(feicao)));
                camada.bindTooltip(() => {
                    const m = regioes.value.find((x) => x.cod === cod);

                    return m
                        ? `${m.nome} — ${plural(m.clientes, 'cliente', 'clientes')} · clique para ver`
                        : `${feicao.properties.nome} — sem clientes`;
                }, { sticky: true, direction: 'top' });
            },
        },
    ).addTo(mapa);

    camadaSelos = L.layerGroup();
    const caixas = [];
    const centros = {};
    camadaMesos.eachLayer((l) => { centros[Number(l.feature.properties.codarea)] = l.getCenter(); });

    for (const m of regioes.value) {
        const centro = centros[m.cod];
        if (! centro) continue;

        const completa = posicionar(centro, `${m.nome} ${fmt(m.clientes)}`, caixas);
        const posicao = completa ?? posicionar(centro, fmt(m.clientes), caixas);
        if (! posicao) continue;

        L.marker(posicao, {
            icon: pill(completa ? m.nome : '', fmt(m.clientes), `mapa-selo-regiao${m.cod === props.regiaoAtiva ? ' mapa-selo-ativo' : ''}`),
            title: `${m.nome} — ${plural(m.clientes, 'cliente', 'clientes')}`,
            keyboard: false,
        }).on('click', () => abrirRegiao(m.cod)).addTo(camadaSelos);
    }

    camadaSelos.addTo(mapa);
}

/* ── Navegação ───────────────────────────────────────────────────────────────────── */

function entrar(uf) {
    ufAtiva.value = uf;
    desenhar();
}

function voltarAoBrasil() {
    ufAtiva.value = null;
    desenhar();
}

function desenhar() {
    if (! leaflet.value || ! instancia.value || ! camadaUfs || ! props.mapa) return;

    // Um estado só no recorte (vendedor regional, ou filtro de estado): entra direto.
    if (! ufAtiva.value && estados.value.length === 1) ufAtiva.value = estados.value[0].uf;

    // O estado em que a pessoa estava deixou de existir no recorte novo.
    if (ufAtiva.value && ! estadoAtivo.value) ufAtiva.value = null;

    if (ufAtiva.value) {
        desenharEstado();
    } else {
        desenharBrasil();
    }
}

onMounted(async () => {
    try {
        const [{ default: L }, ufs] = await Promise.all([
            import('leaflet'),
            carregarUfs(),
            import('leaflet/dist/leaflet.css'),
        ]);

        if (! el.value) return; // a pessoa saiu da aba antes de carregar

        leaflet.value = L;

        const mapa = L.map(el.value, {
            // `tolerance`: a área de clique de cada região ganha 6 px de folga.
            renderer: L.canvas({ tolerance: 6 }),
            attributionControl: false,
            minZoom: 3,
            maxZoom: 9,
            zoomSnap: 0.25,
            // A roda do mouse continua rolando a PÁGINA: o mapa fica no meio da tela, e
            // com o zoom na roda quem só queria descer até a tabela ficava preso nele.
            scrollWheelZoom: false,
        });

        camadaUfs = L.geoJSON(ufs, {
            style: estiloDoEstado,
            onEachFeature: (feicao, camada) => {
                const uf = ufDaFeicao(feicao);
                camada._uf = uf;
                layersUf.push(camada);

                camada.on('click', () => {
                    if (uf && uf !== ufAtiva.value && estados.value.some((e) => e.uf === uf)) entrar(uf);
                });
                // O tooltip é ligado por `tooltipsUf()`, que o desliga no nível estado.
            },
        }).addTo(mapa);

        instancia.value = mapa;
        pronto.value = true;

        // Voltando da lista ("Voltar ao mapa"): reabre no estado da região que estava aberta.
        if (props.regiaoAtiva) ufAtiva.value = ufDaMeso(props.regiaoAtiva);

        desenhar();
    } catch (e) {
        console.error('[carteira] mapa não carregou:', e);
        erro.value = 'Não foi possível carregar o mapa agora.';
    }
});

watch(() => [props.mapa, props.status], desenhar);

onBeforeUnmount(() => instancia.value?.remove());
</script>

<template>
    <DarkCard title="Mapa da Carteira" :subtitle="resumo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M12 21s-6.5-5.6-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21Z" stroke-linejoin="round" />
                <circle cx="12" cy="10.5" r="2.25" />
            </svg>
        </template>

        <p v-if="erro" class="rounded border border-amber/40 bg-amber/10 px-3 py-2 text-sm text-gray-700">{{ erro }}</p>

        <template v-else>
            <!-- Onde estou, e o caminho de volta. -->
            <nav class="mb-2 flex flex-wrap items-center gap-1.5 text-sm" aria-label="Nível do mapa">
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 font-semibold transition"
                    :class="! ufAtiva ? 'text-gray-800' : 'text-teal underline decoration-teal/40 underline-offset-2 hover:decoration-teal'"
                    :disabled="! ufAtiva"
                    @click="voltarAoBrasil"
                >
                    Brasil
                </button>
                <template v-if="ufAtiva">
                    <span class="text-gray-400">›</span>
                    <span class="px-1.5 py-0.5 font-semibold text-gray-800">{{ nomeUf(ufAtiva) }}</span>
                </template>
                <span class="ml-1 text-xs text-gray-500">
                    <template v-if="! ufAtiva">Clique num estado.</template>
                    <template v-else>{{ carregandoRegioes ? 'Carregando as regiões…' : 'Clique numa região para ver os clientes dela.' }}</template>
                </span>
            </nav>

            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="relative">
                    <!-- `z-0` cria o contexto de empilhamento: os painéis do Leaflet usam
                         z-index até 1000 e passariam por cima da navbar e dos modais.
                         Fundo inline: o `.leaflet-container` traz `background: #ddd` e
                         vence a utility do Tailwind. -->
                    <div ref="el" class="z-0 h-[380px] w-full rounded border border-gray-200 sm:h-[560px]" style="background: #f3f4f6" />
                    <p v-if="! pronto || ! mapa" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">
                        Carregando o mapa…
                    </p>
                </div>

                <!-- A lista acompanha o nível e faz o mesmo que o mapa. -->
                <div v-if="pronto && mapa" class="flex min-h-0 flex-col rounded border border-gray-200 sm:h-[560px]">
                    <!-- BRASIL: estados -->
                    <template v-if="! ufAtiva">
                        <p class="border-b border-gray-200 bg-gray-50 px-3 py-2 text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                            Estados · clientes
                        </p>
                        <ul class="max-h-72 flex-1 overflow-y-auto sm:max-h-none">
                            <li v-for="e in estados" :key="e.uf">
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full items-center gap-2 border-b border-gray-100 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50 sm:min-h-0"
                                    @click="entrar(e.uf)"
                                >
                                    <span class="w-7 shrink-0 font-semibold text-gray-800">{{ e.uf }}</span>
                                    <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded bg-gray-100">
                                        <span class="block h-full rounded" :style="{ width: `${Math.max(2, (e.clientes / maiorEstado) * 100)}%`, background: cor }" />
                                    </span>
                                    <span class="w-14 shrink-0 text-right tabular-nums text-gray-700">{{ fmt(e.clientes) }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>

                    <!-- ESTADO: resumo + regiões -->
                    <template v-else-if="estadoAtivo">
                        <div class="border-b border-gray-200 bg-gray-50 px-3 py-2">
                            <p class="text-sm font-semibold text-gray-800">{{ plural(estadoAtivo.clientes, 'cliente', 'clientes') }}</p>
                            <p class="text-xs text-gray-600">{{ numeros(estadoAtivo) }}</p>
                            <p v-if="quebraPorStatus(estadoAtivo)" class="text-xs text-gray-500">{{ quebraPorStatus(estadoAtivo) }}</p>
                            <button
                                type="button"
                                class="mt-2 min-h-11 w-full rounded border border-navy px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-navy transition hover:bg-navy hover:text-white sm:min-h-0"
                                @click="emit('abrir-estado', ufAtiva)"
                            >
                                Ver os clientes de {{ ufAtiva }}
                            </button>
                        </div>
                        <p class="border-b border-gray-200 px-3 py-1.5 text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                            {{ plural(regioes.length, 'região', 'regiões') }} · clique para ver os clientes
                        </p>
                        <ul class="max-h-72 flex-1 overflow-y-auto sm:max-h-none">
                            <li v-for="m in regioes" :key="m.cod">
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full items-center gap-2 border-b border-gray-100 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50 sm:min-h-0"
                                    :class="m.cod === regiaoAtiva ? 'bg-amber/10' : ''"
                                    :title="quebraPorStatus(m)"
                                    @click="abrirRegiao(m.cod)"
                                >
                                    <span class="min-w-0 flex-1 truncate text-gray-800">{{ m.nome }}</span>
                                    <span class="h-1.5 w-12 shrink-0 overflow-hidden rounded bg-gray-100">
                                        <span class="block h-full rounded" :style="{ width: `${Math.max(4, (m.clientes / maiorRegiao) * 100)}%`, background: cor }" />
                                    </span>
                                    <span class="w-12 shrink-0 text-right tabular-nums text-gray-700">{{ fmt(m.clientes) }}</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 shrink-0 text-gray-400">
                                        <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                <template v-if="! ufAtiva">Quanto mais escuro o estado, mais clientes.</template>
                <template v-else>Regiões do IBGE (mesorregiões); quanto mais escura, mais clientes.</template>
                Cliente com lojas em mais de um lugar aparece em todos eles, e uma vez só no total.
                <template v-if="semLocalizacao">
                    {{ plural(semLocalizacao, 'endereço', 'endereços') }} sem município reconhecido
                    {{ semLocalizacao === 1 ? 'ficou' : 'ficaram' }} fora do mapa.
                </template>
            </p>
        </template>
    </DarkCard>
</template>

<!--
    Global, e não `scoped`: o Leaflet cria estes elementos fora da árvore do Vue e eles
    não recebem o `data-v-*` (mesmo gotcha do TriangleMosaic). Os nomes são prefixados
    para não vazar para o resto do sistema.
-->
<style>
.mapa-selo-icone {
    background: none;
    border: 0;
}

.mapa-selo {
    position: absolute;
    transform: translate(-50%, -50%);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    padding: 1px 6px;
    border: 1px solid #0f3a69;
    border-radius: 4px;
    background: #fff;
    color: #111827;
    font-size: 11px;
    line-height: 16px;
    font-variant-numeric: tabular-nums;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.15);
    cursor: pointer;
}

.mapa-selo b {
    color: #0f3a69;
    font-weight: 700;
}

.mapa-selo b:empty {
    display: none;
}

.mapa-selo:hover {
    background: #0f3a69;
    color: #fff;
}

.mapa-selo:hover b {
    color: #fff;
}

/* Região: o nome é mais longo que a sigla do estado, então vem em peso normal. */
.mapa-selo-regiao b {
    font-weight: 600;
}

/* A região que está filtrada na lista (voltando pelo "Voltar ao mapa"). */
.mapa-selo-ativo {
    border-color: #ff8f00;
    box-shadow: 0 0 0 2px rgb(255 143 0 / 0.35);
}
</style>
