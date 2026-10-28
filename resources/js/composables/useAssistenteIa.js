/**
 * O estado do chat do assistente de IA.
 *
 * ⚠️ O ESTADO MORA NO ESCOPO DO MÓDULO, não no componente. O layout é remontado a cada
 * visita do Inertia (ver o AuthenticatedLayout): estado de componente sumiria a cada clique
 * no menu, e a pessoa perderia a conversa — e a resposta que ainda estava chegando — só
 * por ter trocado de página. Módulo JS vive enquanto a aba estiver aberta.
 *
 * A resposta chega por dois caminhos, de propósito:
 * - o canal privado do Reverb (`ia.evento`), ao vivo, palavra por palavra;
 * - polling de `GET /ia/mensagens/{id}` quando o canal fica mudo por alguns segundos.
 * O segundo existe porque o WebSocket cai (rede do celular, Reverb reiniciando) e uma
 * resposta que nunca aparece é pior que uma que aparece de uma vez.
 */
import { ref } from 'vue';
import { postJson, tokenCsrf } from '@/utils/csrf';

const SILENCIO_ANTES_DO_POLLING_MS = 8000;
const INTERVALO_POLLING_MS = 2500;

export const aberto = ref(false);
export const conversaId = ref(null);
export const mensagens = ref([]);
export const progresso = ref(null);
export const aviso = ref(null);
export const conversas = ref(null);
export const enviando = ref(false);

let ultimoSinal = 0;
let relogio = null;

function indice(id) {
    return mensagens.value.findIndex((m) => m.id === id);
}

function substituir(mensagem) {
    const i = indice(mensagem.id);
    if (i !== -1) {
        mensagens.value[i] = { ...mensagens.value[i], ...mensagem };
    }
}

function pendente() {
    return mensagens.value.find((m) => m.papel === 'ia' && m.status === 'processando') ?? null;
}

export function aguardandoResposta() {
    return pendente() !== null;
}

/** O que chega pelo Reverb. Ignora evento de mensagem que não está na tela. */
export function aplicarEvento(evento) {
    if (indice(evento.mensagemId) === -1) {
        return;
    }
    ultimoSinal = Date.now();

    // Resposta que o usuário parou: eventos atrasados do job não a ressuscitam na tela.
    if (mensagens.value[indice(evento.mensagemId)].status === 'cancelada') {
        return;
    }

    if (evento.tipo === 'delta') {
        substituir({ id: evento.mensagemId, texto: evento.texto });
    } else if (evento.tipo === 'ferramenta') {
        progresso.value = evento.rotulo;
    } else if (evento.tipo === 'concluida' || evento.tipo === 'erro') {
        substituir(evento.mensagem);
        progresso.value = null;
        pararPolling();
    }
}

function pararPolling() {
    clearInterval(relogio);
    relogio = null;
}

/** Vigia a resposta pendente: se o canal ficar mudo, passa a perguntar ao servidor. */
function vigiar() {
    pararPolling();
    ultimoSinal = Date.now();

    relogio = setInterval(async () => {
        const atual = pendente();
        if (!atual) {
            pararPolling();
            return;
        }
        if (Date.now() - ultimoSinal < SILENCIO_ANTES_DO_POLLING_MS) {
            return;
        }
        try {
            const res = await fetch(route('ia.mensagem', atual.id), { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const m = await res.json();
                if (m.status !== 'processando') {
                    substituir(m);
                    progresso.value = null;
                    pararPolling();
                }
            }
        } catch {
            /* rede caiu: tenta de novo no próximo tique */
        }
    }, INTERVALO_POLLING_MS);
}

export async function enviar(texto, tela) {
    texto = texto.trim();
    if (!texto || enviando.value || aguardandoResposta()) {
        return false;
    }

    enviando.value = true;
    aviso.value = null;

    try {
        const res = await postJson(route('ia.enviar'), { texto, conversa_id: conversaId.value, tela });
        const corpo = await res.json().catch(() => ({}));

        if (!res.ok) {
            aviso.value = corpo.erro ?? corpo.message ?? 'Não foi possível enviar. Tente de novo.';
            return false;
        }

        conversaId.value = corpo.conversaId;
        mensagens.value.push(corpo.pergunta, corpo.resposta);
        conversas.value = null;

        if (corpo.resposta.status === 'processando') {
            progresso.value = 'Pensando…';
            vigiar();
        }

        return true;
    } catch {
        aviso.value = 'Sem conexão com o servidor. Tente de novo.';
        return false;
    } finally {
        enviando.value = false;
    }
}

/**
 * "Parar": corta a resposta em andamento. Devolve o texto da pergunta, para o painel pôr
 * de volta na caixa — quem para é porque o modelo entendeu errado e vai reescrever.
 *
 * A tela muda NA HORA, sem esperar o servidor: quem aperta "Parar" quer que pare.
 */
export async function parar() {
    const atual = pendente();
    if (!atual) {
        return null;
    }

    const i = indice(atual.id);
    const pergunta = i > 0 ? mensagens.value[i - 1] : null;

    pararPolling();
    progresso.value = null;
    substituir({ id: atual.id, status: 'cancelada' });
    if (pergunta) {
        substituir({ id: pergunta.id, status: 'cancelada' });
    }

    try {
        const res = await postJson(route('ia.parar', atual.id));
        const corpo = await res.json();
        // Terminou um instante antes do clique: a resposta fica, e a pergunta também.
        if (!corpo.cancelou) {
            substituir(corpo.mensagem);
            if (corpo.pergunta) {
                substituir(corpo.pergunta);
            }
            return null;
        }
    } catch {
        /* sem rede: na tela já parou; o servidor descarta quando o job acabar */
    }

    return pergunta?.texto ?? null;
}

export function novaConversa() {
    pararPolling();
    conversaId.value = null;
    mensagens.value = [];
    progresso.value = null;
    aviso.value = null;
}

export async function carregarConversas(limite = 20) {
    const res = await fetch(route('ia.conversas', { limite }), { headers: { Accept: 'application/json' } });
    conversas.value = res.ok ? await res.json() : [];
}

export async function abrirConversa(id) {
    const res = await fetch(route('ia.conversa', id), { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        return;
    }
    const corpo = await res.json();
    pararPolling();
    conversaId.value = corpo.id;
    mensagens.value = corpo.mensagens;
    progresso.value = null;
    aviso.value = null;
    if (pendente()) {
        progresso.value = 'Pensando…';
        vigiar();
    }
}

export async function avaliar(mensagem, valor) {
    const novo = mensagem.avaliacao === valor ? null : valor;
    substituir({ id: mensagem.id, avaliacao: novo });

    await fetch(route('ia.avaliar', mensagem.id), {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenCsrf(),
        },
        body: JSON.stringify({ avaliacao: novo }),
    }).catch(() => {});
}
