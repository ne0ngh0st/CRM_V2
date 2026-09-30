<script>
/*
 * A geografia é estática e igual para todo mundo: contorno das UFs, contorno das
 * mesorregiões e a coordenada da sede de cada município. Fica em `public/geo/`, o
 * navegador guarda em cache, e o servidor só manda CONTAGEM por código IBGE.
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

let geografia = null;

function carregarGeografia() {
    geografia ??= Promise.all([buscarJson('/geo/ufs.json'), buscarJson('/geo/municipios.json')])
        .then(([ufs, municipios]) => ({ ufs, municipios }))
        .catch((e) => {
            geografia = null; // deixa tentar de novo na próxima abertura da aba

            throw e;
        });

    return geografia;
}

// 538 KB (134 KB comprimido): só é baixado quando alguém entra num estado.
let malhaMesos = null;

function carregarMesorregioes() {
    malhaMesos ??= buscarJson('/geo/mesorregioes.json').catch((e) => {
        malhaMesos = null;

        throw e;
    });

    return malhaMesos;
}

// `codarea` do GeoJSON do IBGE (= os dois primeiros dígitos do código do município).
const UF_POR_CODIGO = {
    11: 'RO', 12: 'AC', 13: 'AM', 14: 'RR', 15: 'PA', 16: 'AP', 17: 'TO', 21: 'MA', 22: 'PI',
    23: 'CE', 24: 'RN', 25: 'PB', 26: 'PE', 27: 'AL', 28: 'SE', 29: 'BA', 31: 'MG', 32: 'ES',
    33: 'RJ', 35: 'SP', 41: 'PR', 42: 'SC', 43: 'RS', 50: 'MS', 51: 'MT', 52: 'GO', 53: 'DF',
};
const CODIGO_POR_UF = Object.fromEntries(Object.entries(UF_POR_CODIGO).map(([c, uf]) => [uf, Number(c)]));

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
 * TRÊS NÍVEIS, e cada um só mostra o que cabe nele (feedback do Tony em 2026-09-30: as
 * 3.290 cidades de uma vez viravam mancha; depois, o estado aberto sem divisão interna
 * ainda era confuso — "falta delimitação por mesorregião"):
 *
 *   1. BRASIL — estados pintados pela quantidade de clientes, com selo "SP 21.834".
 *   2. ESTADO — dividido nas mesorregiões do IBGE, pintadas e com selo de nome e
 *      número. Sem bolha de cidade: é aqui que se escolhe a REGIÃO.
 *   3. MESORREGIÃO — as cidades só daquela região, em bolhas, com os nomes que cabem;
 *      as vizinhas ficam em cinza como referência.
 *
 * A lista ao lado acompanha o nível (estados → mesorregiões → cidades) e abre tudo o que
 * o mapa abre: ninguém precisa mirar numa bolha.
 *
 * O mapa não decide quem está na lista (isso é do servidor, pela mesma `baseQuery()` da
 * tabela); aqui só se desenha. As regras de grão que a tela tem que respeitar:
 *
 *   - "N clientes" de um estado ou de uma cidade é o total da lista que o botão abre;
 *   - nenhum total é soma de outro: cliente com lojas em duas cidades está nas duas
 *     bolhas e uma vez no selo da região e no do estado;
 *   - o tamanho da bolha é o número de FILIAIS COMERCIAIS; entrega é número à parte.
 */
const props = defineProps({
    // { municipios, estados, mesorregioes, semLocalizacao, totais } — ver MapaDaCarteira.php
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
const carregandoRegioes = ref(false);
const ufAtiva = ref(null);
const mesoAtiva = ref(null);
const selecionado = ref(null);

// Objetos do Leaflet fora da reatividade profunda: o Vue não precisa observar o mapa.
const leaflet = shallowRef(null);
const instancia = shallowRef(null);
const geo = shallowRef(null);
const mesosGeo = shallowRef(null);
let camadaUfs = null;
let camadaMesos = null;
let camadaSelos = null;
let camadaBolhas = null;
let camadaRotulos = null;
let anel = null;
let zoomDaRegiao = null;
// As feições de UF, guardadas para ligar/desligar o tooltip conforme o nível.
const layersUf = [];

const nivel = computed(() => (mesoAtiva.value ? 'meso' : ufAtiva.value ? 'estado' : 'brasil'));
const cor = computed(() => COR_STATUS[props.status] ?? COR_PADRAO);
const fmt = (n) => (n ?? 0).toLocaleString('pt-BR');
const plural = (n, um, varios) => `${fmt(n)} ${n === 1 ? um : varios}`;
const nomeUf = (uf) => UFS[uf]?.[0] ?? uf;

const ufDoMunicipio = (cod) => UF_POR_CODIGO[Math.floor(cod / 100000)] ?? null;
const mesoDoMunicipio = (cod) => geo.value?.municipios?.[cod]?.[4] ?? null;
const nomeDoMunicipio = (cod) => geo.value?.municipios?.[cod]?.[2] ?? `Município ${cod}`;
const ufDaMeso = (cod) => UF_POR_CODIGO[Math.floor(cod / 100)] ?? null;

const nomesDasMesos = computed(() => Object.fromEntries(
    (mesosGeo.value?.features ?? []).map((f) => [Number(f.properties.codarea), f.properties.nome]),
));
const nomeMeso = (cod) => nomesDasMesos.value[cod] ?? `Região ${cod}`;

const estados = computed(() => [...(props.mapa?.estados ?? [])].sort((a, b) => b.clientes - a.clientes));
const estadoAtivo = computed(() => estados.value.find((e) => e.uf === ufAtiva.value) ?? null);
const maiorEstado = computed(() => Math.max(1, ...estados.value.map((e) => e.clientes)));

const mesos = computed(() => (props.mapa?.mesorregioes ?? [])
    .filter((m) => ufDaMeso(m.cod) === ufAtiva.value)
    .sort((a, b) => b.clientes - a.clientes));
const mesoAtivaDados = computed(() => mesos.value.find((m) => m.cod === mesoAtiva.value) ?? null);
const maiorMeso = computed(() => Math.max(1, ...mesos.value.map((m) => m.clientes)));

const cidades = computed(() => (props.mapa?.municipios ?? [])
    .filter((m) => (mesoAtiva.value ? mesoDoMunicipio(m.cod) === mesoAtiva.value : ufDoMunicipio(m.cod) === ufAtiva.value))
    .sort((a, b) => b.clientes - a.clientes || b.comerciais - a.comerciais));
const maiorCidade = computed(() => Math.max(1, ...cidades.value.map((c) => c.clientes)));
const maiorFilialDaRegiao = computed(() => Math.max(1, ...cidades.value.map((c) => c.comerciais)));

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
const intensidade = (valor, maior) => 0.1 + 0.55 * Math.sqrt(valor / maior);

/*
 * Colocação sem sobreposição, usada pelos selos de região e pelos nomes de cidade: da
 * maior para a menor, cada caixa só entra se não encostar numa já colocada. A caixa é
 * INFLADA com folga — estimar a largura justa deixava dois nomes vizinhos passarem e
 * encostarem no DOM. Melhor um nome a menos do que dois grudados.
 */
const colide = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

function caixaDeTexto(ponto, texto, deslocY = 0, gap = 5) {
    const w = texto.length * 6.6 + 16 + 2 * gap;
    const h = 20 + 2 * gap;

    return { x: ponto.x - w / 2, y: ponto.y - h / 2 + deslocY, w, h };
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

function limparRegiao() {
    camadaMesos = limparCamada(camadaMesos);
    camadaSelos = limparCamada(camadaSelos);
    camadaBolhas = limparCamada(camadaBolhas);
    camadaRotulos = limparCamada(camadaRotulos);
    anel = limparCamada(anel);
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

    limparRegiao();
    selecionado.value = null;
    zoomDaRegiao = null;

    tooltipsUf(true);
    camadaUfs.setStyle(estiloDoEstado);

    camadaSelos = L.layerGroup();

    estados.value.forEach((e) => {
        const posicao = UFS[e.uf];
        if (! posicao) return;

        L.marker([posicao[1], posicao[2]], {
            icon: selo(e.uf, fmt(e.clientes)),
            title: `${posicao[0]} — ${plural(e.clientes, 'cliente', 'clientes')}`,
            keyboard: false,
        }).on('click', () => entrar(e.uf)).addTo(camadaSelos);
    });

    camadaSelos.addTo(mapa);
    mapa.fitBounds(camadaUfs.getBounds(), { padding: [8, 8] });
}

function selo(titulo, numero, classe = '') {
    const L = leaflet.value;
    const span = document.createElement('span');
    span.className = `mapa-selo ${classe}`;
    span.append(Object.assign(document.createElement('b'), { textContent: titulo }), numero);

    return L.divIcon({ html: span, className: 'mapa-selo-icone', iconSize: null });
}

/* ── Nível 2: estado, dividido em mesorregiões ───────────────────────────────────── */

function estiloDaMeso(feicao) {
    const cod = Number(feicao.properties.codarea);
    const m = mesos.value.find((x) => x.cod === cod);

    // Nível mesorregião: a aberta em branco, as vizinhas em cinza como referência.
    if (mesoAtiva.value) {
        const ativa = cod === mesoAtiva.value;

        return {
            color: ativa ? COR_PADRAO : '#ffffff',
            weight: ativa ? 1.75 : 1.25,
            fillColor: ativa ? '#ffffff' : '#e5e7eb',
            fillOpacity: 1,
        };
    }

    // Nível estado: coroplético por região, com fronteira branca bem visível.
    return {
        color: '#ffffff',
        weight: 1.5,
        fillColor: m ? cor.value : '#f3f4f6',
        fillOpacity: m ? intensidade(m.clientes, maiorMeso.value) : 1,
    };
}

function montarMesos() {
    const L = leaflet.value;
    const codUf = CODIGO_POR_UF[ufAtiva.value];

    camadaMesos = L.geoJSON(
        { type: 'FeatureCollection', features: mesosGeo.value.features.filter((f) => Math.floor(Number(f.properties.codarea) / 100) === codUf) },
        {
            style: estiloDaMeso,
            onEachFeature: (feicao, camada) => {
                const cod = Number(feicao.properties.codarea);

                camada.on('click', () => {
                    if (cod !== mesoAtiva.value && mesos.value.some((m) => m.cod === cod)) entrarMeso(cod);
                });
                // Tooltip só no nível estado: dentro da região, a vizinha é só contexto.
                if (! mesoAtiva.value) {
                    camada.bindTooltip(() => {
                        const m = mesos.value.find((x) => x.cod === cod);

                        return `${feicao.properties.nome} — ${m ? plural(m.clientes, 'cliente', 'clientes') : 'sem clientes'}`;
                    }, { sticky: true, direction: 'top' });
                }
            },
        },
    ).addTo(instancia.value);
}

async function desenharEstado() {
    const L = leaflet.value;
    const mapa = instancia.value;

    limparRegiao();
    tooltipsUf(false);
    camadaUfs.setStyle(estiloDoEstado);

    let limites = null;
    camadaUfs.eachLayer((l) => {
        if (ufDaFeicao(l.feature) === ufAtiva.value) limites = l.getBounds();
    });
    if (limites) mapa.fitBounds(limites, { padding: [20, 20], animate: false });

    if (! mesosGeo.value) {
        carregandoRegioes.value = true;
        try {
            mesosGeo.value = await carregarMesorregioes();
        } finally {
            carregandoRegioes.value = false;
        }
        // A pessoa pode ter mudado de nível enquanto a malha chegava.
        if (nivel.value !== 'estado') return;
    }

    montarMesos();

    // Selos de região: nome + número onde couber, só o número onde não couber.
    camadaSelos = L.layerGroup();
    const caixas = [];
    const centros = {};
    camadaMesos.eachLayer((l) => { centros[Number(l.feature.properties.codarea)] = l.getCenter(); });

    for (const m of mesos.value) {
        const centro = centros[m.cod];
        if (! centro) continue;

        const p = mapa.latLngToContainerPoint(centro);
        const nome = nomeMeso(m.cod);
        const completa = caixaDeTexto(p, `${nome} ${fmt(m.clientes)}`);
        const curta = caixaDeTexto(p, fmt(m.clientes), 0, 2);

        let icone = null;
        if (! caixas.some((b) => colide(completa, b))) {
            caixas.push(completa);
            icone = selo(nome, fmt(m.clientes), 'mapa-selo-regiao');
        } else if (! caixas.some((b) => colide(curta, b))) {
            caixas.push(curta);
            icone = selo('', fmt(m.clientes), 'mapa-selo-regiao');
        }

        if (icone) {
            L.marker(centro, { icon: icone, title: `${nome} — ${plural(m.clientes, 'cliente', 'clientes')}`, keyboard: false })
                .on('click', () => entrarMeso(m.cod))
                .addTo(camadaSelos);
        }
    }

    camadaSelos.addTo(mapa);
}

/* ── Nível 3: uma mesorregião ────────────────────────────────────────────────────── */

/*
 * O raio é quase FIXO em pixels (cresce muito de leve com o zoom): como a bolha é
 * desenhada em pixels, ao aproximar as cidades se afastam na tela e as bolhas se separam
 * sozinhas. Inflar a bolha com o zoom fazia o contrário: quanto mais perto, mais grudado.
 */
function raio(c, zoom) {
    const escala = zoomDaRegiao === null ? 1 : Math.max(0.85, 1 + (zoom - zoomDaRegiao) * 0.12);

    return (5 + 15 * Math.sqrt(c.comerciais / maiorFilialDaRegiao.value)) * escala;
}

async function desenharMeso() {
    const L = leaflet.value;
    const mapa = instancia.value;

    limparRegiao();
    tooltipsUf(false);
    camadaUfs.setStyle(estiloDoEstado);

    if (! mesosGeo.value) mesosGeo.value = await carregarMesorregioes();
    if (nivel.value !== 'meso') return;

    montarMesos();

    let limites = null;
    camadaMesos.eachLayer((l) => {
        if (Number(l.feature.properties.codarea) === mesoAtiva.value) limites = l.getBounds();
    });
    if (limites) mapa.fitBounds(limites, { padding: [24, 24], animate: false });

    zoomDaRegiao = mapa.getZoom();
    camadaBolhas = L.layerGroup();

    // As maiores primeiro: as pequenas ficam por cima e continuam clicáveis.
    [...cidades.value]
        .sort((a, b) => b.comerciais - a.comerciais)
        .forEach((c) => {
            const m = geo.value.municipios[c.cod];
            if (! m) return; // código que a base geográfica não conhece: fica só na lista

            const bolha = L.circleMarker([m[0], m[1]], {
                radius: raio(c, zoomDaRegiao),
                color: cor.value,
                weight: 0.75,
                fillColor: cor.value,
                fillOpacity: c.comerciais ? 0.45 : 0.15,
            });

            bolha.cidade = c;
            bolha.bindTooltip(`${m[2]} — ${plural(c.clientes, 'cliente', 'clientes')}`, { direction: 'top', sticky: true });
            bolha.on('click', () => selecionar(c, false));
            bolha.addTo(camadaBolhas);
        });

    camadaBolhas.addTo(mapa);
    desenharRotulos();

    const cidade = cidades.value.find((c) => c.cod === selecionado.value?.cod);
    if (cidade) marcar(cidade);
}

// Nomes de cidade, sem sobreposição. Refeito a cada zoom: aproximando, cabem mais.
function desenharRotulos() {
    const L = leaflet.value;
    const mapa = instancia.value;

    camadaRotulos = limparCamada(camadaRotulos);
    if (! L || ! mapa || ! geo.value || nivel.value !== 'meso') return;

    camadaRotulos = L.layerGroup();
    const caixas = [];

    for (const c of [...cidades.value].sort((a, b) => b.comerciais - a.comerciais)) {
        if (caixas.length >= 18) break;

        const m = geo.value.municipios[c.cod];
        if (! m) continue;

        const p = mapa.latLngToContainerPoint([m[0], m[1]]);
        // Acima da bolha (o translate do rótulo é -150%).
        const caixa = caixaDeTexto(p, m[2], -(raio(c, mapa.getZoom()) + 12));

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
    if (! L || ! m || nivel.value !== 'meso') return;

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
    mesoAtiva.value = null;
    selecionado.value = null;
    desenhar();
}

function entrarMeso(cod) {
    mesoAtiva.value = cod;
    selecionado.value = null;
    desenhar();
}

function voltarAoBrasil() {
    ufAtiva.value = null;
    mesoAtiva.value = null;
    desenhar();
}

function voltarAoEstado() {
    mesoAtiva.value = null;
    selecionado.value = null;
    desenhar();
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
    if (! ufAtiva.value && estados.value.length === 1) ufAtiva.value = estados.value[0].uf;

    // O nível em que a pessoa estava deixou de existir no recorte novo.
    if (ufAtiva.value && ! estadoAtivo.value) {
        ufAtiva.value = null;
        mesoAtiva.value = null;
    }
    if (mesoAtiva.value && ! mesoAtivaDados.value) mesoAtiva.value = null;

    // Uma região só com cliente no estado: não há o que escolher, entra nela.
    if (ufAtiva.value && ! mesoAtiva.value && mesos.value.length === 1) mesoAtiva.value = mesos.value[0].cod;

    if (nivel.value === 'brasil') {
        desenharBrasil();
    } else if (nivel.value === 'estado') {
        desenharEstado();
    } else {
        desenharMeso();
    }
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
            // `tolerance`: a área de clique de cada bolha e de cada região ganha 6 px de
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
                // O tooltip é ligado por `tooltipsUf()`, que o desliga fora do nível Brasil.
            },
        }).addTo(mapa);

        mapa.on('zoomend', () => {
            if (nivel.value !== 'meso') return;

            redimensionar();
            desenharRotulos();
            if (selecionado.value) marcar(selecionado.value);
        });

        instancia.value = mapa;
        pronto.value = true;

        // Voltando da lista ("Voltar ao mapa"): reabre no estado, na região e na cidade.
        if (props.municipioAtivo) {
            ufAtiva.value = ufDoMunicipio(props.municipioAtivo);
            mesoAtiva.value = mesoDoMunicipio(props.municipioAtivo);
            selecionado.value = props.mapa?.municipios?.find((c) => c.cod === props.municipioAtivo) ?? null;
        }

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
            <!-- Onde estou, e cada degrau clicável para voltar. -->
            <nav class="mb-2 flex flex-wrap items-center gap-1.5 text-sm" aria-label="Nível do mapa">
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 font-semibold transition"
                    :class="nivel === 'brasil' ? 'text-gray-800' : 'text-teal underline decoration-teal/40 underline-offset-2 hover:decoration-teal'"
                    :disabled="nivel === 'brasil'"
                    @click="voltarAoBrasil"
                >
                    Brasil
                </button>
                <template v-if="ufAtiva">
                    <span class="text-gray-400">›</span>
                    <button
                        type="button"
                        class="rounded px-1.5 py-0.5 font-semibold transition"
                        :class="nivel === 'estado' ? 'text-gray-800' : 'text-teal underline decoration-teal/40 underline-offset-2 hover:decoration-teal'"
                        :disabled="nivel === 'estado'"
                        @click="voltarAoEstado"
                    >
                        {{ nomeUf(ufAtiva) }}
                    </button>
                </template>
                <template v-if="mesoAtiva">
                    <span class="text-gray-400">›</span>
                    <span class="px-1.5 py-0.5 font-semibold text-gray-800">{{ nomeMeso(mesoAtiva) }}</span>
                </template>
                <span class="ml-1 text-xs text-gray-500">
                    <template v-if="nivel === 'brasil'">Clique num estado.</template>
                    <template v-else-if="nivel === 'estado'">{{ carregandoRegioes ? 'Carregando as regiões…' : 'Clique numa região.' }}</template>
                    <template v-else>Clique numa cidade, no mapa ou na lista.</template>
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

                <!-- A lista acompanha o nível e abre tudo o que o mapa abre. -->
                <div v-if="pronto && mapa" class="flex min-h-0 flex-col rounded border border-gray-200 sm:h-[560px]">
                    <!-- BRASIL: estados -->
                    <template v-if="nivel === 'brasil'">
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

                    <template v-else-if="estadoAtivo">
                        <!-- Resumo do estado: fica nos dois níveis de dentro, com o botão que
                             abre a lista pelo filtro de Estado. -->
                        <div class="border-b border-gray-200 bg-gray-50 px-3 py-2">
                            <template v-if="nivel === 'meso' && mesoAtivaDados">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ nomeMeso(mesoAtiva) }}</p>
                                <p class="text-sm font-semibold text-gray-800">{{ plural(mesoAtivaDados.clientes, 'cliente', 'clientes') }}</p>
                                <p class="text-xs text-gray-600">{{ numeros(mesoAtivaDados) }}</p>
                                <p v-if="quebraPorStatus(mesoAtivaDados)" class="text-xs text-gray-500">{{ quebraPorStatus(mesoAtivaDados) }}</p>
                            </template>
                            <template v-else>
                                <p class="text-sm font-semibold text-gray-800">{{ plural(estadoAtivo.clientes, 'cliente', 'clientes') }}</p>
                                <p class="text-xs text-gray-600">{{ numeros(estadoAtivo) }}</p>
                                <p v-if="quebraPorStatus(estadoAtivo)" class="text-xs text-gray-500">{{ quebraPorStatus(estadoAtivo) }}</p>
                            </template>
                            <button
                                type="button"
                                class="mt-2 min-h-11 w-full rounded border border-navy px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-navy transition hover:bg-navy hover:text-white sm:min-h-0"
                                @click="emit('abrir-estado', ufAtiva)"
                            >
                                Ver os clientes de {{ ufAtiva }}
                            </button>
                        </div>

                        <!-- ESTADO: regiões -->
                        <template v-if="nivel === 'estado'">
                            <p class="border-b border-gray-200 px-3 py-1.5 text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                                {{ plural(mesos.length, 'região', 'regiões') }} · clientes
                            </p>
                            <ul class="max-h-72 flex-1 overflow-y-auto sm:max-h-none">
                                <li v-for="m in mesos" :key="m.cod">
                                    <button
                                        type="button"
                                        class="flex min-h-11 w-full items-center gap-2 border-b border-gray-100 px-3 py-1.5 text-left text-sm transition hover:bg-gray-50 sm:min-h-0"
                                        @click="entrarMeso(m.cod)"
                                    >
                                        <span class="min-w-0 flex-1 truncate text-gray-800" :title="nomeMeso(m.cod)">{{ nomeMeso(m.cod) }}</span>
                                        <span class="h-1.5 w-12 shrink-0 overflow-hidden rounded bg-gray-100">
                                            <span class="block h-full rounded" :style="{ width: `${Math.max(4, (m.clientes / maiorMeso) * 100)}%`, background: cor }" />
                                        </span>
                                        <span class="w-12 shrink-0 text-right tabular-nums text-gray-700">{{ fmt(m.clientes) }}</span>
                                    </button>
                                </li>
                            </ul>
                        </template>

                        <!-- MESORREGIÃO: cidades -->
                        <template v-else>
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
                    </template>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                <template v-if="nivel === 'brasil'">Quanto mais escuro o estado, mais clientes.</template>
                <template v-else-if="nivel === 'estado'">Regiões do IBGE (mesorregiões); quanto mais escura, mais clientes.</template>
                <template v-else>O tamanho da bolha é o número de filiais na cidade.</template>
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
