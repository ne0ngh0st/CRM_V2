<?php

namespace App\Services\Geografia;

use App\Services\PowerBi\SchemaBi;
use App\Services\PowerBi\ViewsBi;
use Illuminate\Support\Facades\DB;

/**
 * Dono ÚNICO de `clientes.cod_municipio` — o código IBGE do município de cada filial.
 *
 * O TOTVS manda o município como texto (`clientes.municipio`), com grafia antiga, erro
 * de digitação e região administrativa no lugar da cidade. Quem traduz isso para código
 * é o de-para do schema do BI (`de_para_municipio`, chaveado por UF + nome normalizado),
 * que já resolve 99,91% da base. Aqui o resultado vira coluna indexada da própria
 * `clientes`, pelos mesmos motivos de `data_ultimo_contato`:
 *
 *   - o mapa da Carteira agrupa por município e o clique na bolha filtra por ele; com o
 *     código na linha isso é `GROUP BY`/`WHERE` numa coluna inteira indexada, em vez de
 *     um join entre schemas em cima de uma expressão de texto a cada requisição;
 *   - município é o CÓDIGO, nunca o nome: há cidades homônimas em UFs diferentes, e a
 *     mesma cidade aparece com mais de uma grafia.
 *
 * ⚠️ A normalização do nome NÃO é reescrita aqui: é `ViewsBi::nomeMunicipio()`, a mesma
 * expressão SQL das views do Power BI, e a comparação é feita pelo MySQL (collation
 * insensível a acento — é o que casa 'SAO PAULO' com 'SÃO PAULO'). Refazer isso em PHP
 * criaria uma segunda definição de "mesmo município".
 *
 * ⚠️ Não escrever em `cod_municipio` de nenhum outro lugar (import, seeder, controller):
 * a coluna está fora do `$fillable` do `Cliente` de propósito. Os imports de cliente
 * chamam `sincronizar()` no fim; `php artisan clientes:resolver-municipios` roda o mesmo
 * serviço à mão.
 */
class MunicipioSincronizador
{
    /**
     * Resolve o município de todas as filiais e grava só o que mudou.
     *
     * Idempotente: rodar de novo sem mudança no cadastro não escreve nada — é o que
     * permite chamá-lo a cada importação horária. Filial cujo município não resolve
     * fica com `cod_municipio` NULL ("sem localização" no mapa), inclusive a que TINHA
     * código e deixou de resolver depois de uma correção de cadastro no TOTVS.
     *
     * @return int quantas filiais tiveram o código alterado
     */
    public function sincronizar(): int
    {
        if (! SchemaBi::existe()) {
            return 0;
        }

        return DB::affectingStatement(
            'UPDATE clientes c
             LEFT JOIN '.SchemaBi::tabela('de_para_municipio').' d
               ON d.uf = c.estado AND d.nome_norm = '.ViewsBi::nomeMunicipio('c.municipio').'
             SET c.cod_municipio = d.cod_municipio
             WHERE NOT (c.cod_municipio <=> d.cod_municipio)'
        );
    }

    /**
     * Quantas filiais têm município resolvido, para o comando dizer a cobertura.
     *
     * @return array{total: int, resolvidos: int}
     */
    public function cobertura(): array
    {
        $linha = DB::table('clientes')
            ->selectRaw('COUNT(*) as total, COUNT(cod_municipio) as resolvidos')
            ->first();

        return ['total' => (int) $linha->total, 'resolvidos' => (int) $linha->resolvidos];
    }

    /**
     * Os textos de município que o de-para não conhece, do mais frequente ao menos —
     * é a lista de trabalho de quem for acrescentar linha ao `de_para_municipio`.
     *
     * @return list<object{estado: ?string, municipio: ?string, filiais: int}>
     */
    public function naoResolvidos(int $limite = 20): array
    {
        return DB::table('clientes')
            ->whereNull('cod_municipio')
            ->groupBy('estado', 'municipio')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limite)
            ->get(['estado', 'municipio', DB::raw('COUNT(*) as filiais')])
            ->all();
    }
}
