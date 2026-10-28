/**
 * Ditado por voz para a caixa do assistente: fala, vira texto na caixa, a pessoa confere
 * e envia.
 *
 * Usa o reconhecimento de fala do PRÓPRIO NAVEGADOR (Web Speech API): sem custo, sem
 * servidor nosso no caminho. Chrome e Edge (inclusive no Android) têm; Firefox não — ali
 * o botão do microfone simplesmente não aparece (`suportado`).
 *
 * ⚠️ No Chrome o áudio é transcrito pelos servidores do Google (no Edge, da Microsoft).
 * É o navegador que manda, não o CRM. Por isso o texto NUNCA é enviado sozinho: cai na
 * caixa para a pessoa ler antes — reconhecimento de fala erra número de pedido, e um
 * "A23521" virando "a 23 521" mandaria a pergunta errada.
 *
 * ⚠️ Só funciona em página segura (https ou localhost) e pede permissão do microfone na
 * primeira vez.
 */
import { onUnmounted, ref } from 'vue';

const Reconhecimento = typeof window !== 'undefined' ? window.SpeechRecognition || window.webkitSpeechRecognition : null;

export function useDitado() {
    const suportado = Boolean(Reconhecimento);
    const ouvindo = ref(false);
    const erro = ref(null);

    let rec = null;

    /**
     * @param {string} base  o que já estava escrito na caixa (o ditado continua dali)
     * @param {(texto: string) => void} aoMudar  recebe base + o que foi falado até agora
     */
    function iniciar(base, aoMudar) {
        if (!suportado || ouvindo.value) {
            return;
        }

        erro.value = null;
        rec = new Reconhecimento();
        rec.lang = 'pt-BR';
        rec.continuous = true;
        rec.interimResults = true;

        const prefixo = base.trim() ? base.trimEnd() + ' ' : '';
        let finais = '';

        rec.onresult = (evento) => {
            let parcial = '';
            for (let i = evento.resultIndex; i < evento.results.length; i++) {
                const trecho = evento.results[i][0].transcript;
                if (evento.results[i].isFinal) {
                    finais += trecho;
                } else {
                    parcial += trecho;
                }
            }
            aoMudar((prefixo + finais + parcial).replace(/\s+/g, ' ').trimStart());
        };

        rec.onerror = (evento) => {
            erro.value = {
                'not-allowed': 'O navegador bloqueou o microfone. Libere no cadeado da barra de endereço.',
                'service-not-allowed': 'O navegador bloqueou o microfone. Libere no cadeado da barra de endereço.',
                'audio-capture': 'Nenhum microfone encontrado.',
                network: 'Sem conexão com o serviço de voz do navegador.',
                'no-speech': null,
                aborted: null,
            }[evento.error] ?? 'Não consegui ouvir. Tente de novo.';
        };

        rec.onend = () => {
            ouvindo.value = false;
            rec = null;
        };

        try {
            rec.start();
            ouvindo.value = true;
        } catch {
            rec = null;
        }
    }

    function parar() {
        rec?.stop();
    }

    onUnmounted(() => rec?.abort());

    return { suportado, ouvindo, erro, iniciar, parar };
}
