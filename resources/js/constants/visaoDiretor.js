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
