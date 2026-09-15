import { onUnmounted, ref } from 'vue';

/**
 * "A tela é estreita?" — em JavaScript, no MESMO ponto de corte que o CSS usa.
 *
 * ⚠️ ESTE NÚMERO É COMPARTILHADO COM O `app.css`. A media query dos cartões de tabela
 * (`@media (max-width: 639px)`) e este composable descrevem o mesmo limite: o `sm` do
 * Tailwind começa em 640px. Se um dia o breakpoint mudar, muda nos DOIS — e o sintoma de
 * esquecer um é silencioso: a tabela viraria cartão numa largura em que os filtros ainda
 * estão colapsados (ou o contrário), sem nada quebrar em vermelho.
 *
 * ⚠️ EXISTE PORQUE COLAPSAR OS FILTROS NÃO DÁ PARA SER SÓ CSS. Esconder com `sm:hidden`
 * deixaria o conteúdo renderizado DUAS vezes — uma na faixa e outra no modal —, com dois
 * `<select>` para o mesmo filtro e dois `<label>` iguais na árvore. Com o breakpoint em
 * JavaScript, só um dos dois existe a cada momento.
 *
 * Lê `matchMedia` já na criação (não no `onMounted`): este app não tem SSR, então a
 * largura real está disponível na primeira renderização e não há troca visível de layout.
 */
export const LARGURA_COMPACTA = 639;

export function useTelaCompacta() {
    const consulta = typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia(`(max-width: ${LARGURA_COMPACTA}px)`)
        : null;

    const compacta = ref(consulta ? consulta.matches : false);
    const aoTrocar = (ev) => { compacta.value = ev.matches; };

    consulta?.addEventListener('change', aoTrocar);
    onUnmounted(() => consulta?.removeEventListener('change', aoTrocar));

    return { compacta };
}
