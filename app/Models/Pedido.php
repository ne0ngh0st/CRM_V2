<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    /**
     * Por quantos dias um pedido AINDA EM ABERTO continua contando como venda.
     *
     * ⚠️ 180 não é número arredondado à toa: é a política de programação da casa
     * (confirmada pelo Tony em 2026-09-06). Pedido de programação fica legitimamente
     * aberto até seis meses; depois disso, se não faturou, não se concretizou.
     */
    public const DIAS_MAXIMO_EM_ABERTO = 180;

    protected $fillable = [
        'numero_pedido',
        'rps',
        'tipo_faturamento',
        'cliente_id',
        'cod_vendedor',
        'data_pedido',
        'data_previsao_faturamento',
        'data_faturamento',
        'data_entrega_prevista',
        'data_pcp',
        'carga',
        'condicao_pagamento',
        'status',
        'historico_totvs',
        'historico_em',
        'valor_total',
    ];

    protected function casts(): array
    {
        return [
            'data_pedido' => 'date',
            'data_previsao_faturamento' => 'date',
            'data_faturamento' => 'date',
            'data_entrega_prevista' => 'date',
            'data_pcp' => 'date',
            // `datetime`, não `date`: a hora é metade da informação. Dois pedidos com
            // bloqueio de estoque "hoje" contam histórias diferentes se um foi às 07h e
            // o outro há dez minutos.
            'historico_em' => 'datetime',
            'valor_total' => 'decimal:2',
        ];
    }

    /**
     * O recorte que conta como VENDA nas métricas do Painel e das Metas.
     *
     * Pedido faturado sempre conta, com a idade que tiver. Pedido ainda em aberto conta
     * só enquanto for mais novo que {@see DIAS_MAXIMO_EM_ABERTO}.
     *
     * ⚠️ POR QUE ISTO EXISTE: o relatório 200 do TOTVS mantém pedido aberto
     * indefinidamente — há caso real de máquina faturada anos depois. Conferido em
     * produção em 2026-09-06: 111 pedidos abertos de 2023 a 2025, R$ 271 mil. Não é erro
     * de importação (o arquivo-fonte traz exatamente esses 10/34/67 pedidos por ano), e
     * eles não devem ser apagados — mas contá-los como "venda de 2025" faz o gráfico
     * comparar R$ 458 milhões contra o resíduo de um ano que nunca foi carregado.
     *
     * ⚠️ E POR QUE NÃO "só o 232 (faturados)", que seria mais simples: medido no mesmo
     * dia, 83% do valor de SETEMBRO estava em pedido ainda não faturado (R$ 3,3 mi
     * abertos contra R$ 682 mil faturados). Contar só faturado apagaria o mês corrente do
     * vendedor e faria "Venda" virar quase o mesmo número que "Faturamento".
     *
     * O corte em 180 dias tira R$ 508 mil de R$ 42,6 mi em aberto (1,2%) e não encosta na
     * venda recente: 98,8% do valor aberto tem menos de 90 dias.
     *
     * ⚠️ NÃO usar isto nas LISTAGENS (`/pedidos-abertos`, `/pedidos-emitidos`). Lá o
     * vendedor precisa ver o pedido velho justamente porque ele está travado. Isto é
     * recorte de MÉTRICA, não de operação.
     *
     * ⚠️ CUSTO, medido em 2026-09-06: por vendedor a consulta continua entrando pelo
     * índice (mediana 15,6 ms, p95 33,2 ms, máx 48,7 ms), porque `cod_vendedor` filtra
     * antes. No escopo EMPRESA o `OR` derruba o índice — `EXPLAIN` vira `type: ALL` sobre
     * as ~47 mil linhas — e custa 197 ms. Aceito porque o bloco é cacheado por 30 min e
     * pré-aquecido a cada 10.
     *
     * ⚠️ RISCO CONHECIDO: quando o histórico de pedidos emitidos de 2025 entrar (+407 mil
     * linhas, tarefa pendente), esse scan cresce junto e o escopo empresa deve ir para a
     * casa de 1 a 2 s. Se incomodar, a saída barata JÁ ESTÁ IDENTIFICADA e não é índice
     * novo: quando o período consultado começa depois do limite de 180 dias — o caso da
     * série diária e do mês corrente — a condição não pode excluir nada, e pode ser
     * omitida. Medir antes de fazer.
     */
    public function scopeContaComoVenda(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNotNull('data_faturamento')
            ->orWhere('data_pedido', '>=', self::limiteEmAberto()));
    }

    /**
     * O recorte da carteira em aberto que ainda conta para a meta de FATURAMENTO de um mês.
     *
     * Três condições, cada uma com um motivo:
     *
     * 1. EM ABERTO — faturado já está em `faturamentos` e entra pelo realizado. Os dois
     *    conjuntos são disjuntos, então `meta − faturado − este recorte` não conta nada
     *    duas vezes (com a exceção abaixo).
     * 2. PREVISÃO ATÉ O FIM DO MÊS, SEM LIMITE INFERIOR — o atrasado (previsão já vencida)
     *    ainda vai faturar, e em 2026-09-15 era R$ 21,6 mi de R$ 38,3 mi, mais da metade da
     *    carteira. ⚠️ Por isso NÃO é `whereBetween($inicioDoMes, $fimDoMes)`: essa versão
     *    "mais arrumada" apagaria o atrasado da conta sem erro nenhum. O que tem previsão
     *    para o mês seguinte (R$ 4,5 mi) fica de fora — não ajuda a fechar ESTE mês.
     * 3. PEDIDO COM MENOS DE {@see DIAS_MAXIMO_EM_ABERTO} DIAS — o mesmo corte do
     *    `contaComoVenda()`. O resíduo eterno é pequeno no agregado (R$ 352 mil) mas se
     *    concentra: havia vendedor com 66% da carteira em aberto feita dele.
     *
     * ⚠️ PREVISÃO NULA FICA DE FORA, de propósito: o `<=` descarta NULL em silêncio, e
     * previsão ausente não é "vai faturar neste mês". Hoje o TOTVS preenche 100% dos
     * abertos; não trocar por `orWhereNull` "para não perder dado".
     *
     * ⚠️ EXCEÇÃO À DISJUNÇÃO, medida em 2026-09-15: pedido PARCIALMENTE faturado tem a
     * nota parcial em `faturamentos` e continua aqui com o valor cheio (o relatório 200
     * vence o 232 no import — ver ImportPedidosAbertosTotvs). Eram 51 pedidos, com
     * R$ 804,8 mil já faturados: 2,1% da carteira, contados duas vezes, o que faz a
     * "falta" sair MENOR que a real. Corrigir exige casar por `faturamentos.pedido`, que
     * não tem índice (14,8 s para checar 200 pedidos). Aceito e documentado.
     *
     * ⚠️ Comparação crua na coluna `date`, nunca `whereDate()`: função na coluna derruba
     * o índice (Regra de ouro nº 6).
     */
    public function scopeContaParaFaturamentoDe(Builder $query, string $fimDoMes): Builder
    {
        return $query->emAberto()
            ->where('data_previsao_faturamento', '<=', $fimDoMes)
            ->where('data_pedido', '>=', self::limiteEmAberto());
    }

    /**
     * Pedido que ainda não virou nota.
     *
     * É a definição de "em aberto" no banco, e mora aqui para não voltar a ser um
     * `whereNull('data_faturamento')` escrito à mão em cada tela (eram cinco). Os imports
     * do TOTVS continuam com o SQL cru porque escrevem por `DB::table`, sem o model.
     */
    public function scopeEmAberto(Builder $query): Builder
    {
        return $query->whereNull('data_faturamento');
    }

    /**
     * A data a partir da qual um pedido em aberto ainda conta nas métricas.
     *
     * Uma fonte só para os dois recortes acima — com cada um calculando a sua, um dia um
     * ganha `startOfDay()` e o outro não, e os dois números param de bater.
     */
    private static function limiteEmAberto(): string
    {
        return now()->subDays(self::DIAS_MAXIMO_EM_ABERTO)->toDateString();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }
}
