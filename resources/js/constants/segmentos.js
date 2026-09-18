/**
 * Cores estáveis por código TOTVS — o quadro de cobertura pinta cada segmento
 * para se ler de relance, e a cor NÃO pode mudar entre deploys (hash do nome
 * driftaria se a lista crescesse). Teal/cyan/navy/âmbar da marca; o resto é
 * variação desses tons + verde da família de botões, nunca uma paleta nova.
 *
 * Texto e barra precisam de contraste sobre o fundo claro (~4.5:1). Por isso o
 * cyan da marca (#00A9CE) não pinta glifo — usa o -dark, igual aos ícones da
 * tabela.
 *
 * `CODIGO_SUPERMERCADISTA` espelha `Segmento::CODIGO_SUPERMERCADISTA`. O valor
 * canônico é o PHP (é ele que força o representante na escrita); daqui só se
 * lê para pintar e travar o formulário.
 */
export const CODIGO_SUPERMERCADISTA = '101';

export const CORES_SEGMENTO = {
    101: { fundo: '#D9EEF1', barra: '#005A6F', texto: '#003D4A' },
    103: { fundo: '#D6E2EF', barra: '#0F3A69', texto: '#0A2747' },
    104: { fundo: '#D4F0F6', barra: '#007A94', texto: '#005A6F' },
    105: { fundo: '#E8EAED', barra: '#4B5563', texto: '#1F2937' },
    106: { fundo: '#DDE7F0', barra: '#334E68', texto: '#243B53' },
    107: { fundo: '#E4E4E7', barra: '#52525B', texto: '#27272A' },
    108: { fundo: '#D5E8EF', barra: '#0F4C75', texto: '#0F3A69' },
    109: { fundo: '#FDE8C8', barra: '#C46B00', texto: '#7A4200' },
    111: { fundo: '#D9EAF2', barra: '#0369A1', texto: '#0C4A6E' },
    112: { fundo: '#FCE7D3', barra: '#C2410C', texto: '#7C2D12' },
    113: { fundo: '#E5E7EB', barra: '#6B7280', texto: '#374151' },
    114: { fundo: '#F8E7C3', barra: '#B45309', texto: '#78350F' },
    115: { fundo: '#E0EEF4', barra: '#0E7490', texto: '#155E75' },
    116: { fundo: '#F3E8EE', barra: '#9D174D', texto: '#831843' },
    117: { fundo: '#DCEFE8', barra: '#047857', texto: '#065F46' },
    118: { fundo: '#E2E8F0', barra: '#475569', texto: '#1E293B' },
    119: { fundo: '#F5E6D3', barra: '#B45309', texto: '#7C2D12' },
    120: { fundo: '#E8E0D4', barra: '#78716C', texto: '#44403C' },
    121: { fundo: '#E2E8F0', barra: '#334155', texto: '#1E293B' },
    122: { fundo: '#E7F0D9', barra: '#4D7C0F', texto: '#3F6212' },
    123: { fundo: '#D6E4F0', barra: '#1E3A5F', texto: '#0F3A69' },
    124: { fundo: '#E5E7EB', barra: '#57534E', texto: '#292524' },
    125: { fundo: '#D5F0E8', barra: '#0F766E', texto: '#115E59' },
};

const COR_PADRAO = { fundo: '#F3F4F6', barra: '#6B7280', texto: '#374151' };

export function corDoSegmento(codigo) {
    return CORES_SEGMENTO[String(codigo)] || COR_PADRAO;
}
