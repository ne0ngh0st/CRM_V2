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
</script>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import { ROTULOS_STATUS_CARTEIRA } from '@/constants/carteira';

/*
 * Aba Mapa da Carteira: a MESMA lista, vista por onde os endereços estão.
 *
 * O mapa não decide quem está na lista (isso é do servidor, pela mesma `baseQuery()` da
 * tabela); aqui só se desenha. As regras de grão que a tela tem que respeitar:
 *
 *   - o tamanho da bolha é o número de FILIAIS COMERCIAIS; entrega é número à parte;
 *   - "N clientes" de uma bolha é o total da lista que o botão abre;
 *   - o total de clientes vem pronto (`totais.clientes`) e NUNCA é a soma das bolhas:
 *     cliente com lojas em duas cidades está nas duas.
 *
 * Sem tiles e sem Google: contorno do Brasil + bolhas. O nível de rua é o link do
 * endereço para o Google Maps, que já existe na ficha e no cartão CNPJ.
 */
const props = defineProps({
    // { municipios: [{cod, comerciais, entregas, clientes, ativos, inativando, inativos}], semLocalizacao, totais }
    mapa: { type: Object, default: null },
    // Status aplicado na lista ('' = nenhum): com ele, as bolhas assumem a cor do status.
    status: { type: String, default: '' },
    // Código IBGE do município filtrado na lista, para marcar a bolha correspondente.
    municipioAtivo: { type: Number, default: null },
});

const emit = defineEmits(['abrir']);

// Mesmos tons do StatusPill (green/amber/red 600) e o navy da marca quando não há status.
const COR_STATUS = { ativo: '#16a34a', inativando: '#d97706', inativo: '#dc2626' };
const COR_PADRAO = '#0F3A69';

const el = ref(null);
const erro = ref('');
const pronto = ref(false);
const selecionado = ref(null);

// Objetos do Leaflet fora da reatividade profunda: o Vue não precisa observar o mapa.
const leaflet = shallowRef(null);
const instancia = shallowRef(null);
const camada = shallowRef(null);
const geo = shallowRef(null);

const plural = (n, um, varios) => `${n.toLocaleString('pt-BR')} ${n === 1 ? um : varios}`;

const resumo = computed(() => {
    const t = props.mapa?.totais;
    if (! t) return 'Carregando…';

    return [
        plural(t.comerciais, 'filial', 'filiais'),
        t.entregas ? plural(t.entregas, 'ponto de entrega', 'pontos de entrega') : null,
    ].filter(Boolean).join(' · ') + ` · de ${plural(t.clientes, 'cliente', 'clientes')}`;
});

const semLocalizacao = computed(() => {
    const s = props.mapa?.semLocalizacao;

    return s ? s.comerciais + s.entregas : 0;
});

function nomeDe(cod) {
    const m = geo.value?.municipios?.[cod];

    return m ? `${m[2]}/${m[3]}` : `Município ${cod}`;
}

function quebraPorStatus(b) {
    // "Ativo: 23", e não "23 ativo": o rótulo é o mesmo da pill da lista, sem flexionar.
    return [
        b.ativos ? `${ROTULOS_STATUS_CARTEIRA.ativo}: ${b.ativos.toLocaleString('pt-BR')}` : null,
        b.inativando ? `${ROTULOS_STATUS_CARTEIRA.inativando}: ${b.inativando.toLocaleString('pt-BR')}` : null,
        b.inativos ? `${ROTULOS_STATUS_CARTEIRA.inativo}: ${b.inativos.toLocaleString('pt-BR')}` : null,
    ].filter(Boolean).join(' · ');
}

function descricao(b) {
    return [
        plural(b.clientes, 'cliente', 'clientes'),
        plural(b.comerciais, 'filial', 'filiais'),
        b.entregas ? `+${plural(b.entregas, 'entrega', 'entregas')}` : null,
    ].filter(Boolean).join(' · ');
}

/*
 * O raio cresce com o zoom. Com raio fixo, as 3.290 cidades do escopo empresa viravam
 * uma mancha única cobrindo o Sudeste no zoom do país (visto no navegador, não em teste)
 * e, aproximando, as bolhas ficavam pequenas demais para acertar com o dedo.
 */
function raio(b, maior, zoom) {
    const escala = Math.max(0.7, 1 + (zoom - 5.5) * 0.45);

    return (1.6 + 13 * Math.sqrt(b.comerciais / maior)) * escala;
}

function redimensionar() {
    const mapa = instancia.value;
    if (! mapa || ! camada.value || ! props.mapa) return;

    const maior = Math.max(1, ...props.mapa.municipios.map((b) => b.comerciais));

    camada.value.eachLayer((m) => m.setRadius(raio(m.bolha, maior, mapa.getZoom())));
}

function desenhar() {
    const L = leaflet.value;
    const mapa = instancia.value;

    if (! L || ! mapa || ! geo.value || ! props.mapa) return;

    camada.value?.remove();
    selecionado.value = null;

    const cor = COR_STATUS[props.status] ?? COR_PADRAO;
    const maior = Math.max(1, ...props.mapa.municipios.map((b) => b.comerciais));
    const grupo = L.layerGroup();
    const pontos = [];

    // As maiores primeiro: as pequenas ficam por cima e continuam clicáveis.
    [...props.mapa.municipios]
        .sort((a, b) => b.comerciais - a.comerciais)
        .forEach((b) => {
            const m = geo.value.municipios[b.cod];
            if (! m) return; // código que a base geográfica não conhece: fica só na lista

            const ativo = b.cod === props.municipioAtivo;
            const ponto = [m[0], m[1]];
            pontos.push(ponto);

            // Área proporcional às filiais comerciais (raio ∝ raiz), com piso para a
            // cidade de uma loja só — ou só de entregas — continuar visível e clicável.
            const marcador = L.circleMarker(ponto, {
                radius: raio(b, maior, mapa.getZoom()),
                color: ativo ? '#ff8f00' : cor,
                weight: ativo ? 3 : 0.75,
                fillColor: cor,
                fillOpacity: b.comerciais ? 0.45 : 0.12,
            });

            marcador.bolha = b;

            const dica = document.createElement('div');
            dica.append(Object.assign(document.createElement('strong'), { textContent: nomeDe(b.cod) }));
            dica.append(document.createElement('br'), descricao(b));

            marcador.bindTooltip(dica, { direction: 'top', sticky: true });
            marcador.on('click', () => { selecionado.value = b; });
            marcador.addTo(grupo);
        });

    grupo.addTo(mapa);
    camada.value = grupo;

    // Enquadra onde a carteira está: o vendedor de um estado só não precisa ver o país.
    if (pontos.length) {
        mapa.fitBounds(L.latLngBounds(pontos).pad(0.25), { maxZoom: 8 });
    }

    redimensionar();
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
            preferCanvas: true,
            attributionControl: false,
            // A roda do mouse continua rolando a PÁGINA: o mapa fica no meio da tela, e
            // com o zoom na roda quem só queria descer até a tabela ficava preso nele.
            // Zoom pelos botões, duplo clique ou pinça.
            scrollWheelZoom: false,
            minZoom: 3,
            maxZoom: 10,
            zoomSnap: 0.5,
        });

        const contorno = L.geoJSON(dados.ufs, {
            interactive: false,
            style: { color: '#9ca3af', weight: 1, fillColor: '#ffffff', fillOpacity: 1 },
        }).addTo(mapa);

        mapa.fitBounds(contorno.getBounds());
        mapa.on('zoomend', redimensionar);

        instancia.value = mapa;
        pronto.value = true;
        desenhar();
    } catch (e) {
        console.error('[carteira] mapa não carregou:', e);
        erro.value = 'Não foi possível carregar o mapa agora.';
    }
});

watch(() => [props.mapa, props.status, props.municipioAtivo], desenhar);

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
            <p class="mb-2 text-xs text-gray-500">
                Cada bolha é um município; o tamanho é o número de filiais. Cliente com lojas
                em mais de uma cidade aparece em todas elas.
                <template v-if="semLocalizacao">
                    {{ plural(semLocalizacao, 'endereço', 'endereços') }} sem município reconhecido
                    {{ semLocalizacao === 1 ? 'ficou' : 'ficaram' }} fora do mapa.
                </template>
            </p>

            <div class="relative">
                <!-- `z-0` cria o contexto de empilhamento: os painéis do Leaflet usam
                     z-index até 1000 e passariam por cima da navbar e dos modais. -->
                <!-- Fundo inline: o `.leaflet-container` do Leaflet traz `background: #ddd`
                     e vence a utility do Tailwind. -->
                <div ref="el" class="z-0 h-[420px] w-full rounded border border-gray-200 sm:h-[560px]" style="background: #f3f4f6" />
                <p v-if="! pronto || ! mapa" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">
                    Carregando o mapa…
                </p>
            </div>

            <!-- Clique na bolha SELECIONA; quem abre a lista é o botão. No celular não
                 existe hover, e navegar no primeiro toque esconderia os números. -->
            <div
                v-if="selecionado"
                class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded border border-gray-200 bg-gray-50 px-3 py-2"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-800">{{ nomeDe(selecionado.cod) }}</p>
                    <p class="text-xs text-gray-600">{{ descricao(selecionado) }}</p>
                    <p v-if="quebraPorStatus(selecionado)" class="text-xs text-gray-500">{{ quebraPorStatus(selecionado) }}</p>
                </div>
                <button
                    type="button"
                    class="min-h-11 rounded border border-navy bg-navy px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white transition hover:bg-navy/90 sm:min-h-0"
                    @click="emit('abrir', selecionado.cod)"
                >
                    Ver {{ plural(selecionado.clientes, 'cliente', 'clientes') }}
                </button>
            </div>
            <p v-else-if="pronto && mapa" class="mt-3 text-xs text-gray-400">Clique numa bolha para ver os clientes daquela cidade.</p>
        </template>
    </DarkCard>
</template>
