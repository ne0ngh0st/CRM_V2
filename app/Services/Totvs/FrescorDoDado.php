<?php

namespace App\Services\Totvs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quão velho está o dado que veio do TOTVS.
 *
 * ⚠️ MEDE O DADO, NUNCA O PROCESSO — e a diferença entre os dois já custou um mês a este
 * projeto. Até 2026-09-08 a pill "Sistema:" do Painel lia `data_sync_status`, uma tabela
 * que SÓ O SEEDER escrevia: em produção ela estava congelada em 10/08 e a pill anunciou
 * "Desatualizado" por um mês seguido, inclusive logo depois de uma importação que trouxe
 * 6 milhões de faturamentos. Mesmo defeito do `users.last_activity_at` (badge "0 online
 * agora", 31/08): uma coluna que parecia alimentada e não era.
 *
 * A correção óbvia — o `AtualizadorTotvs` carimbar a tabela ao terminar — mediria a coisa
 * errada pelo segundo motivo: em agosto o import rodou de hora em hora dizendo
 * "sem_mudanca", com sucesso, enquanto o dado envelhecia trinta dias porque ninguém
 * gerava o relatório no TOTVS. Pill verde o mês inteiro. O que interessa a quem abre o
 * Painel não é "o import rodou", é "a última nota fiscal é de quando".
 *
 * ⚠️ Esta é a ÚNICA definição de frescor do sistema (Regra de ouro nº 8). A tela
 * `/atualizacoes` tinha a sua, privada dentro do controller, e a pill tinha outra — foi
 * assim que uma pôde ficar certa enquanto a outra mentia por um mês.
 *
 * ⚠️ O `MAX()` fica AO VIVO, sem cache: as duas colunas são a primeira chave de um índice,
 * então o MySQL lê a última entrada e para — 0,3 ms medido em produção com 6 milhões de
 * linhas. Cachear o rótulo de frescor por 30 min é a única forma de torná-lo mentiroso.
 * (O `COUNT(*)` da mesma tela é outra história: 943 ms, e por isso é cacheado à parte.)
 */
class FrescorDoDado
{
    /**
     * ⚠️ CONTADO EM DIAS ÚTEIS, não corridos. Nota fiscal não sai em fim de semana: numa
     * segunda-feira a última nota é de sexta, e um limiar em dias corridos pintaria a
     * pill de amarelo TODA segunda, sem nada de errado. Alarme que dispara sozinho toda
     * semana é alarme que as pessoas aprendem a ignorar — que é exatamente o que se quer
     * evitar aqui. A folga de dois dias também absorve feriado no meio da semana.
     */
    private const DIAS_UTEIS_ATENCAO = 2;

    private const DIAS_UTEIS_DESATUALIZADO = 3;

    /**
     * Um item por domínio importado, do mais velho para o mais novo.
     *
     * @return list<array{dominio: string, tabela: string, relatorio: string, data: ?string, dias: ?int, status: string}>
     */
    public function porDominio(): array
    {
        // ⚠️ UMA query, não duas. Cada `MAX()` sozinho custa 0,3 ms, então isto não é
        // sobre tempo: é sobre o teto de queries do Painel
        // (`Performance\OrcamentoDeQueriesTest`), que existe para que um N+1 novo apareça
        // como falha em vez de como lentidão. Duas leituras aqui consumiriam a folga do
        // teto sem motivo. As subconsultas continuam cobertas pelo índice — o MySQL lê a
        // última entrada de cada um e para.
        $datas = DB::selectOne(
            'SELECT (SELECT MAX(data_emissao) FROM faturamentos) AS faturamento,
                    (SELECT MAX(data_pedido) FROM pedidos) AS pedido'
        );

        $itens = [
            [
                'dominio' => 'Faturamento',
                'tabela' => 'faturamentos',
                'relatorio' => '198 — FAT',
                'data' => $datas->faturamento,
            ],
            [
                'dominio' => 'Pedidos',
                'tabela' => 'pedidos',
                'relatorio' => '200 + 232',
                'data' => $datas->pedido,
            ],
        ];

        $itens = array_map(fn (array $item) => $item + $this->classificar($item['data']), $itens);

        usort($itens, fn (array $a, array $b) => ($b['dias'] ?? PHP_INT_MAX) <=> ($a['dias'] ?? PHP_INT_MAX));

        return $itens;
    }

    /**
     * O pior domínio — é o que a pill do Painel mostra.
     *
     * Uma pill só, e não uma por tabela: o vendedor não precisa saber qual relatório
     * atrasou, precisa saber se pode confiar no número que está vendo. O detalhe por
     * domínio fica em `/atualizacoes`, que é onde alguém vai agir sobre isso.
     *
     * @return array{dominio: string, tabela: string, relatorio: string, data: ?string, dias: ?int, status: string}|null
     */
    public function pior(): ?array
    {
        return $this->porDominio()[0] ?? null;
    }

    /** @return array{dias: ?int, status: string} */
    private function classificar(?string $data): array
    {
        if ($data === null) {
            // Tabela vazia. Não é "atualizado" nem "atrasado": é ausência de dado, e
            // pintar de verde seria a pior das três leituras possíveis.
            return ['dias' => null, 'status' => 'sem_dado'];
        }

        $dias = (int) Carbon::parse($data)->startOfDay()->diffInWeekdays(now()->startOfDay());

        return [
            'dias' => $dias,
            'status' => match (true) {
                $dias < self::DIAS_UTEIS_ATENCAO => 'atualizado',
                $dias < self::DIAS_UTEIS_DESATUALIZADO => 'atencao',
                default => 'desatualizado',
            },
        ];
    }
}
