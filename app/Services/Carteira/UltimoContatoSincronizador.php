<?php

namespace App\Services\Carteira;

use App\Models\Ligacao;
use Illuminate\Support\Facades\DB;

/**
 * Mantém `clientes.data_ultimo_contato` / `canal_ultimo_contato`.
 *
 * ⚠️ ESTE É O ÚNICO PONTO DE ESCRITA DESSAS DUAS COLUNAS.
 * São valor desnormalizado (o "mais recente" de `ligacoes`), e valor desnormalizado
 * deriva assim que tem dois donos. Não gravar nelas de controller, import ou seeder —
 * registre a `Ligacao` e deixe o hook `Ligacao::created()` chamar isto.
 *
 * ⚠️ A REGRA DE DESEMPATE VIVE AQUI, e nos dois métodos ela tem que ser a MESMA:
 * mais recente vence; em empate de `data_ligacao`, vence o `id` maior. Se `aoRegistrar`
 * e `reconstruirTudo` divergirem, a coluna muda de valor toda vez que alguém rodar a
 * reconstrução — um bug que só aparece semanas depois e parece "a tela mudou sozinha".
 *
 * Por que existe desnormalização aqui: ordenar a Carteira por `MAX(data_ligacao)` de
 * `ligacoes` custa 987 ms no escopo admin, contra 1,2 ms lendo coluna indexada daqui.
 * Números completos na migration `2026_09_02_110000`.
 */
class UltimoContatoSincronizador
{
    /**
     * Atualiza o cliente depois de um contato registrado.
     *
     * ⚠️ O `WHERE` é o que implementa o desempate: só grava se o contato novo for mais
     * recente que o guardado (ou se não houver nada guardado). O `>=` é deliberado —
     * empate significa "chegou depois", então o contato mais novo vence, igual ao
     * `ORDER BY data_ligacao DESC, id DESC` da reconstrução.
     *
     * Um UPDATE por contato registrado. São alguns milhares por mês no sistema
     * inteiro; o custo é irrelevante perto dos ~987 ms por CARREGAMENTO de página que
     * a alternativa (agregar na hora) cobraria.
     */
    public function aoRegistrar(Ligacao $ligacao): void
    {
        if ($ligacao->cliente_id === null || $ligacao->status === 'excluida') {
            return;
        }

        DB::table('clientes')
            ->where('id', $ligacao->cliente_id)
            ->where(function ($q) use ($ligacao) {
                $q->whereNull('data_ultimo_contato')
                    ->orWhere('data_ultimo_contato', '<=', $ligacao->data_ligacao);
            })
            ->update([
                'data_ultimo_contato' => $ligacao->data_ligacao,
                'canal_ultimo_contato' => $ligacao->tipo_contato,
            ]);
    }

    /**
     * Reconstrói a coluna inteira a partir de `ligacoes`.
     *
     * Usado pela migration que criou as colunas e pelo comando
     * `carteira:recalcular-ultimo-contato`. É idempotente por construção: o valor é
     * derivado, então rodar de novo sempre devolve o mesmo resultado.
     *
     * ⚠️ Cliente que perdeu todos os contatos volta a NULL — o `LEFT JOIN` existe pra
     * isso. Sem ele, um contato apagado deixaria a coluna apontando para um registro
     * que não existe mais, e a reconstrução não consertaria.
     *
     * @return int clientes afetados
     */
    public function reconstruirTudo(): int
    {
        return DB::update("
            UPDATE clientes c
            LEFT JOIN (
                SELECT cliente_id, tipo_contato, data_ligacao FROM (
                    SELECT cliente_id, tipo_contato, data_ligacao,
                           ROW_NUMBER() OVER (PARTITION BY cliente_id ORDER BY data_ligacao DESC, id DESC) AS rn
                    FROM ligacoes
                    WHERE cliente_id IS NOT NULL AND status <> 'excluida'
                ) AS ordenados WHERE rn = 1
            ) u ON u.cliente_id = c.id
            SET c.data_ultimo_contato = u.data_ligacao,
                c.canal_ultimo_contato = u.tipo_contato
        ");
    }
}
