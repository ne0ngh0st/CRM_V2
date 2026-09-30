<script>
/*
 * A geografia é estática e igual para todo mundo: contorno das UFs e a coordenada da
 * sede de cada município. Fica em `public/geo/`, o navegador guarda em cache, e o
 * servidor só manda CONTAGEM por código IBGE — nunca coordenada.
 *
 * ⚠️ `<script>` normal, não `<script setup>`: a promessa tem que ser uma por página, não
 * uma por instância do componente (mesmo motivo da `pilha` do `Modal.vue`).
 */
let geografia = null;

function carregarGeografia() {
    geografia ??= Promise.all(
        ['/geo/ufs.json', '/geo/municipios.json'].map((url) => fetch(url).then((r) => {
            if (! r.ok) throw new Error(`${url}: HTTP ${r.status}`);

            return r.json();
        })),
    ).then(([ufs, municipios]) => ({ ufs, municipios })).catch((e) => {
        geografia = null; // deixa tentar de novo na próxima abertura da aba

        throw e;
    });

    return geografia;
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
 */
//
// ⚠️ RN, PB, PE, AL e SE ficam NO MAR, em coluna, de propósito: os cinco cabem em 600 km
// de litoral e qualquer posição em terra põe um selo em cima do outro (visto no
// navegador). ES e RJ também saem para a costa, pelo mesmo motivo.
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
 * DOIS NÍVEIS, e a separação é o que torna o mapa usável (a primeira versão punha as
 * 3.290 cidades do escopo empresa de uma vez no mapa do país — virava uma mancha, e
 * acertar uma bolha com o mouse era sorte; feedback do Tony em 2026-09-30):
 *
 *   1. BRASIL — cada estado pintado pela quantidade de clientes, com um selo
 *      "SP 12.345". Clicar no estado (no mapa ou na lista ao lado) entra nele.
 *   2. ESTADO — só as cidades daquele estado, em bolhas grandes, com as maiores
 *      rotuladas. A lista ao lado tem TODAS as cidades: quem não acerta a bolha clica
 *      na linha.
 *
 * O mapa não decide quem está na lista (isso é do servidor, pela mesma `baseQuery()` da
 * tabela); aqui só se desenha. As regras de grão que a tela tem que respeitar:
 *
 *   - "N clientes" de um estado ou de uma cidade é o total da lista que o botão abre;
 *   - nenhum total é soma de outro: cliente com lojas em duas cidades está nas duas
 *     bolhas e uma vez no selo do estado;
 *   - o tamanho da bolha é o número de FILIAIS COMERCIAIS; entrega é número à parte.
 *
 * Sem tiles e sem Google. O nível de rua é o link do endereço para o Google Maps.
 */
const props = defineProps({
    // { municipios: [...], estados: [...], semLocalizacao, totais } — ver MapaDaCarteira.php
    mapa: { type: Object, default: null },
    // Status aplicado na lista ('' = nenhum): com ele, o mapa assume a cor do status.
    status: { type: String, default: '' },
    // Código IBGE do município filtrado na lista: o mapa reabre nele.
    municipioAtivo: { type: Number, default: null },
});

const emit = defineEmits(['abrir', 'abrir-estado']);

// Mesmos tons do StatusPill (green/amber/red 600) e o navy da marca quando não há status.
const COR_STATUS = { ativo: '#16a34a', inativando: '#d97706', inativo: '#dc2626' };
const COR_PADRAO = '#0F3A69';
const COR_DESTAQUE = '#ff8f00';

const el = ref(null);
const erro = ref('');
const pronto = ref(false);
const ufAtiva = ref(null);
const selecionado = ref(null);

// Objetos do Leaflet fora da reatividade profunda: o Vue não precisa observar o mapa.
const leaflet = shallowRef(null);
const instancia = shallowRef(null);
const geo = shallowRef(null);
let camadaUfs = null;
let camadaSelos = null;
let camadaBolhas = null;
let camadaRotulos = null;
let anel = null;
let zoomDoEstado = null;
// As feições de UF, guardadas para ligar/desligar o tooltip conforme o nível.
const layersUf = [];

const cor = computed(() => COR_STATUS[props.status] ?? COR_PADRAO);
const fmt = (n) => (n ?? 0).toLocaleString('pt-BR');
const plural = (n, um, varios) => `${fmt(n)} ${n === 1 ? um : varios}`;
const nomeUf = (uf) => UFS[uf]?.[0] ?? uf;

const ufDoMunicipio = (cod) => UF_POR_CODIGO[Math.floor(cod / 100000)] ?? null;
const nomeDoMunicipio = (cod) => geo.value?.municipios?.[cod]?.[2] ?? `Município ${cod}`;

const estados = computed(() => [...(props.mapa?.estados ?? [])].sort((a, b) => b.clientes - a.clientes));
const estadoAtivo = computed(() => estados.value.find((e) => e.uf === ufAtiva.value) ?? null);
const maiorEstado = computed(() => Math.max(1, ...estados.value.map((e) => e.clientes)));

const cidades = computed(() => (props.mapa?.municipios ?? [])
    .filter((m) => ufDoMunicipio(m.cod) === ufAtiva.value)
    .sort((a, b) => b.clientes - a.clientes || b.comerciais - a.comerciais));
const maiorCidade = computed(() => Math.max(1, ...cidades.value.map((c) => c.clientes)));
const maiorFilialDoEstado = computed(() => Math.max(1, ...cidades.value.map((c) => c.comerciais)));

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

/*
 * O tooltip da UF só existe no nível Brasil. Sem isto, passar o mouse pelo estado VIZINHO
 * (cinza) dentro do nível estado abria um tooltip "Mato Grosso do Sul — 454 clientes" por
 * cima do estado aberto — o fantasma que aparecia ao entrar em SP. `unbindTooltip` também
 * fecha o que estiver aberto.
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
    const e = estados.value.find((x) => x.uf === uf);

    if (ufAtiva.value) {
        const ativa = uf === ufAtiva.value;

        return {
            color: ativa ? COR_PADRAO : '#d1d5db',
            weight: ativa ? 1.5 : 1,
            fillColor: ativa ? '#ffffff' : '#e5e7eb',
            fillOpacity: 1,
        };
    }

    // Coroplético: a intensidade diz onde a carteira está antes de qualquer número.
    return {
        color: '#9ca3af',
        weight: 1,
        fillColor: e ? cor.value : '#ffffff',
        fillOpacity: e ? 0.1 + 0.55 * Math.sqrt(e.clientes / maiorEstado.value) : 1,
    };
}

function desenharBrasil() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaBolhas = limparCamada(camadaBolhas);
    camadaRotulos = limparCamada(camadaRotulos);
    anel = limparCamada(anel);
    camadaSelos = limparCamada(camadaSelos);
    selecionado.value = null;
    zoomDoEstado = null;

    tooltipsUf(true);
    camadaUfs.setStyle(estiloDoEstado);

    camadaSelos = L.layerGroup();

    estados.value.forEach((e) => {
        const posicao = UFS[e.uf];
        if (! posicao) return;

        const selo = document.createElement('span');
        selo.className = 'mapa-selo';
        selo.append(Object.assign(document.createElement('b'), { textContent: e.uf }), fmt(e.clientes));

        L.marker([posicao[1], posicao[2]], {
            icon: L.divIcon({ html: selo, className: 'mapa-selo-icone', iconSize: null }),
            title: `${posicao[0]} — ${plural(e.clientes, 'cliente', 'clientes')}`,
            keyboard: false,
        }).on('click', () => entrar(e.uf)).addTo(camadaSelos);
    });

    camadaSelos.addTo(mapa);
    mapa.fitBounds(camadaUfs.getBounds(), { padding: [8, 8] });
}

/* ── Nível 2: um estado ──────────────────────────────────────────────────────────── */

/*
 * O raio é quase FIXO em pixels (cresce muito de leve com o zoom): como a bolha é
 * desenhada em pixels, ao aproximar as cidades se afastam na tela e as bolhas se separam
 * sozinhas — é o que desfaz a sopa da região metropolitana. Inflar a bolha com o zoom
 * (a versão anterior dobrava) fazia o contrário: quanto mais perto, mais grudado.
 *
 * Piso de 4 px para a cidade de uma loja só continuar clicável; 3 px num estado com
 * centenas de cidades (MG tem 467 com cliente), senão o sul do estado vira mancha.
 */
function raio(c, zoom) {
    const escala = zoomDoEstado === null ? 1 : Math.max(0.85, 1 + (zoom - zoomDoEstado) * 0.12);
    const piso = cidades.value.length > 250 ? 3 : 4;

    return (piso + 14 * Math.sqrt(c.comerciais / maiorFilialDoEstado.value)) * escala;
}

function desenharEstado() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaSelos = limparCamada(camadaSelos);
    camadaBolhas = limparCamada(camadaBolhas);
    camadaRotulos = limparCamada(camadaRotulos);
    anel = limparCamada(anel);

    tooltipsUf(false);
    camadaUfs.setStyle(estiloDoEstado);

    let limites = null;
    camadaUfs.eachLayer((l) => {
        if (ufDaFeicao(l.feature) === ufAtiva.value) limites = l.getBounds();
    });

    if (limites) {
        mapa.fitBounds(limites, { padding: [24, 24], animate: false });
    }

    zoomDoEstado = mapa.getZoom();
    camadaBolhas = L.layerGroup();

    // As maiores primeiro: as pequenas ficam por cima e continuam clicáveis.
    [...cidades.value]
        .sort((a, b) => b.comerciais - a.comerciais)
        .forEach((c) => {
            const m = geo.value.municipios[c.cod];
            if (! m) return; // código que a base geográfica não conhece: fica só na lista

            const bolha = L.circleMarker([m[0], m[1]], {
                radius: raio(c, zoomDoEstado),
                color: cor.value,
                weight: 0.6,
                fillColor: cor.value,
                fillOpacity: c.comerciais ? 0.4 : 0.15,
            });

            bolha.cidade = c;
            bolha.bindTooltip(`${m[2]} — ${plural(c.clientes, 'cliente', 'clientes')}`, { direction: 'top', sticky: true });
            bolha.on('click', () => selecionar(c, false));
            bolha.addTo(camadaBolhas);
        });

    camadaBolhas.addTo(mapa);
    desenharRotulos();
}

/*
 * Nomes de cidade SEM sobreposição. Rotular as N maiores em posição fixa empilhava
 * "Osasco / Guarulhos / São Bernardo do Campo" na Grande São Paulo. Aqui cada candidato
 * (da maior para a menor) só ganha nome se a caixa dele não encostar numa já colocada —
 * então a região metropolitana mostra uma cidade, não seis por cima uma da outra.
 *
 * Refeito a cada zoom (`zoomend`): aproximando, as cidades se afastam e mais nomes cabem.
 */
function desenharRotulos() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaRotulos = limparCamada(camadaRotulos);
    if (! L || ! mapa || ! geo.value) return;

    camadaRotulos = L.layerGroup();
    const caixas = [];
    const colide = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

    for (const c of [...cidades.value].sort((a, b) => b.comerciais - a.comerciais)) {
        if (caixas.length >= 16) break;

        const m = geo.value.municipios[c.cod];
        if (! m) continue;

        const p = mapa.latLngToContainerPoint([m[0], m[1]]);
        // Caixa de colisão INFLADA com folga (gap de 5 px): estimar a largura justa deixava
        // dois nomes vizinhos passarem e encostarem no DOM ("São Paulo"/"São Bernardo do
        // Campo"). A folga larga é o que mantém o mapa arejado — melhor um nome a menos do
        // que dois grudados.
        const gap = 5;
        const w = m[2].length * 6.6 + 12 + 2 * gap;
        // Acima da bolha (o translate do rótulo é -150%).
        const caixa = { x: p.x - w / 2, y: p.y - raio(c, mapa.getZoom()) - 22 - gap, w, h: 18 + 2 * gap };

        if (caixas.some((b) => colide(caixa, b))) continue;

        caixas.push(caixa);
        L.marker([m[0], m[1]], {
            interactive: false,
            icon: L.divIcon({ html: Object.assign(document.createElement('span'), { className: 'mapa-rotulo', textContent: m[2] }), className: 'mapa-rotulo-icone', iconSize: null }),
        }).addTo(camadaRotulos);
    }

    camadaRotulos.addTo(mapa);
}

function redimensionar() {
    const mapa = instancia.value;
    if (! mapa || ! camadaBolhas) return;

    camadaBolhas.eachLayer((l) => l.cidade && l.setRadius(raio(l.cidade, mapa.getZoom())));
}

function marcar(c) {
    const L = leaflet.value;
    const m = geo.value?.municipios?.[c.cod];

    anel = limparCamada(anel);
    if (! L || ! m) return;

    anel = L.circleMarker([m[0], m[1]], {
        radius: raio(c, instancia.value.getZoom()) + 4,
        color: COR_DESTAQUE,
        weight: 3,
        fill: false,
        interactive: false,
    }).addTo(instancia.value);
}

/* ── Navegação ───────────────────────────────────────────────────────────────────── */

function entrar(uf) {
    ufAtiva.value = uf;
    selecionado.value = null;
    desenharEstado();
}

function voltarAoBrasil() {
    ufAtiva.value = null;
    desenharBrasil();
}

// `centralizar`: só quando a escolha veio da LISTA — quem clicou na bolha já está olhando para ela.
function selecionar(c, centralizar = true) {
    selecionado.value = c;
    marcar(c);

    const m = geo.value?.municipios?.[c.cod];
    if (centralizar && m) instancia.value.panTo([m[0], m[1]]);
}

function desenhar() {
    if (! leaflet.value || ! instancia.value || ! geo.value || ! props.mapa) return;

    // Um estado só no recorte (vendedor regional, ou filtro de estado): entra direto.
    if (estados.value.length === 1) ufAtiva.value = estados.value[0].uf;

    // O estado em que a pessoa estava deixou de existir no recorte novo.
    if (ufAtiva.value && ! estadoAtivo.value) ufAtiva.value = null;

    if (! ufAtiva.value) {
        desenharBrasil();

        return;
    }

    const anterior = selecionado.value?.cod;
    desenharEstado();

    const cidade = cidades.value.find((c) => c.cod === anterior);
    selecionado.value = cidade ?? null;
    if (cidade) marcar(cidade);
}

onMounted(async () => {
    try {
        const [{ default: L }, dados] = await Promise.all([
            import('leaflet'),
            carregarGeografia(),
            import('leaflet/dist/leaflet.css'),
        ]);

        if (! el.value) return; // a pessoa saiu da aba antes de carregar

        leaflet.value = L;
        geo.value = dados;

        const mapa = L.map(el.value, {
            // `tolerance`: a área de clique de cada bolha e de cada estado ganha 6 px de
            // folga. Sem isso, acertar uma bolha pequena exigia mira.
            renderer: L.canvas({ tolerance: 6 }),
            attributionControl: false,
            minZoom: 3,
            maxZoom: 11,
            zoomSnap: 0.25,
            // A roda do mouse continua rolando a PÁGINA: o mapa fica no meio da tela, e
            // com o zoom na roda quem só queria descer até a tabela ficava preso nele.
            scrollWheelZoom: false,
        });

        camadaUfs = L.geoJSON(dados.ufs, {
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

        mapa.on('zoomend', () => {
            if (! ufAtiva.value) return;

            redimensionar();
            desenharRotulos();
            if (selecionado.value) marcar(selecionado.value);
        });

        instancia.value = mapa;
        pronto.value = true;

        // Voltando da lista ("Voltar ao mapa"): reabre no estado e na cidade de onde saiu.
        if (props.municipioAtivo) ufAtiva.value = ufDoMunicipio(props.municipioAtivo);

        desenhar();

        const cidade = cidades.value.find((c) => c.cod === props.municipioAtivo);
        if (cidade) selecionar(cidade, false);
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
            <!-- Onde estou + o caminho de volta. -->
            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                <button
                    v-if="ufAtiva"
                    type="button"
                    class="inline-flex min-h-11 items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 sm:min-h-0"
                    @click="voltarAoBrasil"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                        <polyline points="15,6 9,12 15,18" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Brasil
                </button>
                <p class="font-semibold text-gray-800">{{ ufAtiva ? nomeUf(ufAtiva) : 'Brasil' }}</p>
                <p class="text-xs text-gray-500">
                    {{ ufAtiva ? 'Clique numa cidade, no mapa ou na lista.' : 'Clique num estado para ver as cidades.' }}
                </p>
            </div>

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

                <!-- A lista é o caminho de quem não quer (ou não consegue) mirar no mapa:
                     tudo o que o mapa abre, ela abre também. -->
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

                    <!-- ESTADO: resumo + cidades -->
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
                            {{ plural(cidades.length, 'cidade', 'cidades') }} · clientes
                        </p>
                        <ul class="max-h-72 flex-1 overflow-y-auto sm:max-h-none">
                            <li v-for="c in cidades" :key="c.cod" class="border-b border-gray-100" :class="selecionado?.cod === c.cod ? 'bg-amber/10' : ''">
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50 sm:min-h-0"
                                    @click="selecionar(c)"
                                >
                                    <span class="min-w-0 flex-1 truncate text-gray-800" :title="nomeDoMunicipio(c.cod)">{{ nomeDoMunicipio(c.cod) }}</span>
                                    <span class="h-1.5 w-12 shrink-0 overflow-hidden rounded bg-gray-100">
                                        <span class="block h-full rounded" :style="{ width: `${Math.max(4, (c.clientes / maiorCidade) * 100)}%`, background: cor }" />
                                    </span>
                                    <span class="w-12 shrink-0 text-right tabular-nums text-gray-700">{{ fmt(c.clientes) }}</span>
                                </button>
                                <!-- A cidade escolhida abre AQUI, na própria linha: os números e
                                     o botão ficam onde o olho já está. -->
                                <div v-if="selecionado?.cod === c.cod" class="px-3 pb-2">
                                    <p class="text-xs text-gray-600">{{ numeros(c) }}</p>
                                    <p v-if="quebraPorStatus(c)" class="text-xs text-gray-500">{{ quebraPorStatus(c) }}</p>
                                    <button
                                        type="button"
                                        class="mt-1.5 min-h-11 w-full rounded border border-navy bg-navy px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white transition hover:bg-navy/90 sm:min-h-0"
                                        @click="emit('abrir', c.cod)"
                                    >
                                        Ver {{ plural(c.clientes, 'cliente', 'clientes') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                <template v-if="ufAtiva">O tamanho da bolha é o número de filiais na cidade.</template>
                <template v-else>Quanto mais escuro o estado, mais clientes.</template>
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
.mapa-selo-icone,
.mapa-rotulo-icone {
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

.mapa-selo:hover {
    background: #0f3a69;
    color: #fff;
}

.mapa-selo:hover b {
    color: #fff;
}

.mapa-rotulo {
    position: absolute;
    transform: translate(-50%, -150%);
    white-space: nowrap;
    padding: 0 4px;
    border-radius: 3px;
    background: rgb(255 255 255 / 0.85);
    color: #1f2937;
    font-size: 11px;
    font-weight: 600;
    line-height: 15px;
    pointer-events: none;
}
</style>
