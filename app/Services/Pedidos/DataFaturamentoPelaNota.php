<?php

namespace App\Services\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * Preenche `pedidos.data_faturamento` com a data da nota que já está em `faturamentos`.
 *
 * O 232 gravaria essa data, mas ele é recorte pela data de EMISSÃO do pedido: a nota
 * que sai no mês seguinte não volta naquele arquivo, e o arquivo velho é relido toda
 * hora com `DT_FATURAMENTO` em branco. Gerar de novo cada mês fechado não é o fluxo.
 *
 * O 200 só sabe que o pedido saiu da carteira — não distingue nota de cancelamento, e
 * por isso não inventa data (ver ImportPedidosAbertosTotvs::marcarForaDo200). Quem
 * distingue é o relatório 198, já importado em `faturamentos`.
 *
 * ⚠️ Pedido que AINDA está no 200 não entra, mesmo com nota parcial. Faturamento
 * parcial continua em aberto, com o valor cheio — é o que o 232 soma.
 *
 * ⚠️ Não mexe em valor, que continua sendo do 232. O status vira `faturado`, como o
 * 232 faria se trouxesse a nota: sem isso o pedido fechado mantinha a etapa do último
 * 200 ("Incluído na carga") e a ficha do cliente mostrava as duas coisas ao mesmo tempo.
 * A data é a da última nota (`MAX(data_emissao)`), a mesma regra do 232 quando há mais
 * de um item.
 *
 * O índice em `faturamentos.pedido` existe para esta junção. Sem ele, cruzar os
 * poucos mil pedidos fora do 200 com os milhões de notas varre a tabela (medido em
 * 2026-10-20: 34 s).
 */
class DataFaturamentoPelaNota
{
    public function aplicar(): int
    {
        return DB::update('
            UPDATE pedidos p
            INNER JOIN (
                SELECT f.pedido AS numero, MAX(f.data_emissao) AS data_nota
                FROM faturamentos f
                INNER JOIN pedidos abertos
                    ON abertos.numero_pedido = f.pedido
                   AND abertos.data_faturamento IS NULL
                   AND abertos.fora_do_200 = 1
                GROUP BY f.pedido
            ) nota ON nota.numero = p.numero_pedido
            SET p.data_faturamento = nota.data_nota,
                p.status = ?,
                p.updated_at = ?
            WHERE p.data_faturamento IS NULL
              AND p.fora_do_200 = 1
        ', [StatusPedidoResolver::FATURADO, now()]);
    }
}
