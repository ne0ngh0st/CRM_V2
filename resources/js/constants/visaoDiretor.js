/**
 * Rótulos e cores da Visão Diretor → Maiores por Segmento.
 *
 * O status da conta é o MESMO da Carteira (ativo / inativando / inativo — "Perdendo" e
 * "A trabalhar" na tela), mais `lead`: nenhuma loja nossa. Por isso estende o mapa da
 * Carteira em vez de copiá-lo — Regra de ouro nº 8.
 *
 * ⚠️ `MaioresPorSegmentoExport.php` tem a cópia do lado do servidor — mudou aqui, muda lá.
 */
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira';

export const ROTULOS_STATUS_CONTA = { ...ROTULOS_STATUS_CARTEIRA, lead: 'Lead' };

export const TONS_STATUS_CONTA = { ...TONS_STATUS_CARTEIRA, lead: 'neutral' };

export const STATUS_CONTA = ['ativo', 'inativando', 'inativo', 'lead'];

/*
 * Maiores redes (redes do mercado): só dois status, decisão do Tony em 2026-10-16. A rede
 * é Ativa com pelo menos um cliente ativo; lead, perdendo e inativo são "A trabalhar".
 * ⚠️ `RelatorioDeRedes` (o Excel) usa os mesmos rótulos do lado do servidor.
 */
export const ROTULOS_STATUS_REDE = { ativo: 'Ativo', a_trabalhar: 'A trabalhar' };

export const TONS_STATUS_REDE = { ativo: 'ok', a_trabalhar: 'warn' };

/** Como o cliente entrou na rede (coluna `nivel` de `redes_mercado_vinculos`). */
export const ROTULOS_NIVEL_VINCULO = {
    nome: 'Pelo nome',
    grupo: 'Grupo do TOTVS',
    produto: 'Produto com o nome',
    mesma_empresa: 'Mesma empresa (CNPJ)',
    manual: 'Vinculado à mão',
};

// Veredito da rede na Receita (`SituacaoDaRede`). O rótulo vem pronto do servidor; aqui só a cor.
export const TONS_SITUACAO_REDE = { ativa: 'ok', fechada: 'danger', sem_informacao: 'neutral' };
