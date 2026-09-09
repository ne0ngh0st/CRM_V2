<script setup>
/*
 * A pill de etapa do pedido no TOTVS — ou nada, quando não há etapa reconhecida.
 *
 * ⚠️ O RÓTULO VEM DO SERVIDOR (`pedido.statusRotulo`), não de um mapa local. Quem decide
 * quais status existem e como se chamam é `App\Services\Pedidos\StatusPedidoResolver`.
 * Aqui só mora a COR. As três telas que mostram status de pedido — /pedidos-abertos,
 * /pedidos-emitidos e a ficha do cliente — usam este componente; antes cada uma tinha sua
 * própria cópia da marcação, e uma delas já ficou para trás exibindo a string crua do
 * enum (Regra de ouro nº 8).
 *
 * ⚠️ SEM RÓTULO, SEM PILL. Um travessão discreto no lugar, nunca um rótulo genérico do
 * tipo "aguardando classificação" — era exatamente isso que a tela mostrava em 100% das
 * linhas até 2026-09-09, ocupando uma coluna inteira sem dizer nada. O texto cru do TOTVS
 * continua disponível ao expandir a linha, então nada se perde ao não desenhar a pill.
 */
import StatusPill from '@/Components/StatusPill.vue';
import { TONS_STATUS_PEDIDO } from '@/constants/pedidos.js';

defineProps({
    // O rótulo já resolvido no servidor; null quando o movimento não foi reconhecido.
    rotulo: { type: String, default: null },
    // A chave do status, usada só para escolher a cor.
    status: { type: String, default: null },
});
</script>

<template>
    <StatusPill v-if="rotulo" :tone="TONS_STATUS_PEDIDO[status] || 'neutral'" size="sm">
        {{ rotulo }}
    </StatusPill>
    <span v-else class="text-gray-300">—</span>
</template>
