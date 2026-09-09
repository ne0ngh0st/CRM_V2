<?php

namespace App\Services\Exportacao;

use App\Exports\CadastroExport;
use App\Exports\CarteiraExport;
use App\Exports\EquipeExport;
use App\Exports\LeadExport;
use App\Exports\MetasExport;
use App\Exports\OrcamentoExport;
use App\Exports\PedidoAbertoExport;
use App\Exports\PedidoEmitidoExport;
use App\Exports\TabelaPrecoExport;
use App\Http\Controllers\CadastroController;
use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\EquipeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\TabelaPrecoController;
use App\Models\User;
use App\Services\Carteira\ClienteStatusResolver;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Tudo que o sistema sabe exportar: o rótulo de cada planilha e como montá-la.
 *
 * ⚠️ POR QUE ISTO EXISTE (Regra de ouro nº 8). A mesma planilha precisa ser montada em
 * DOIS contextos — dentro da requisição (volume pequeno, baixa na hora) e dentro do job
 * (volume grande, avisa pelo sino). Com a montagem no controller, o job carregaria uma
 * segunda cópia da regra — e o `GerarExportacaoCarteiraJob` já era essa segunda cópia.
 * Duas cópias divergem sozinhas: um filtro novo entra na tela e o Excel silenciosamente
 * deixa de acompanhá-lo, sem erro nenhum, só com o arquivo errado.
 *
 * ⚠️ A AUTORIZAÇÃO MORA AQUI, não no controller, pelo mesmo motivo: precisa valer nos
 * dois caminhos. Como o plano é sempre montado dentro da requisição (é ele que conta as
 * linhas para decidir o caminho), o `abort_unless` continua devolvendo 403 na hora do
 * clique, exatamente como antes.
 *
 * ⚠️ A QUERY ATRAVESSA A FILA PELOS FILTROS, nunca pelo Builder nem pelos IDs. Builder do
 * Eloquent não é serializável de forma confiável, e serializar os IDs do escopo admin da
 * Carteira seria uma lista de 90 mil inteiros — ~700 KB de payload no Redis e um
 * `whereIn` gigante no SQL. Guardar os filtros custa poucos bytes e reconstrói a MESMA
 * query da tela, chamando o método que a própria tela usa.
 */
class CatalogoDeExportacoes
{
    /**
     * Recurso => rótulo exibido na central.
     *
     * ⚠️ O rótulo é resolvido no SERVIDOR e enviado pronto para o front, que não tem
     * cópia deste mapa — mesma decisão do StatusPedidoResolver. Rótulo duplicado no Vue
     * foi como a ficha do cliente ficou meses mostrando a string crua `pendente_totvs`:
     * um valor novo entra e a cópia do front fica para trás em silêncio.
     */
    public const RECURSOS = [
        'carteira' => 'Carteira de clientes',
        'leads' => 'Leads',
        'orcamentos' => 'Orçamentos',
        'pedidos-abertos' => 'Pedidos em aberto',
        'pedidos-emitidos' => 'Pedidos emitidos',
        'tabela-precos' => 'Tabela de preços',
        'equipe' => 'Equipe',
        'metas' => 'Metas',
        'cadastros-bobina' => 'Solicitações de bobina',
        'cadastros-etiqueta' => 'Solicitações de etiqueta',
        'cadastros-cliente' => 'Solicitações de cliente novo',
        'cadastros-lead' => 'Solicitações de lead',
    ];

    public function rotulo(string $recurso): string
    {
        return self::RECURSOS[$recurso] ?? $recurso;
    }

    public function existe(string $recurso): bool
    {
        return isset(self::RECURSOS[$recurso]);
    }

    /**
     * Monta a planilha de um recurso a partir dos filtros da tela.
     *
     * ⚠️ O usuário é passado explicitamente, e não lido de `Auth`: no job não há sessão
     * nem usuário logado, e o escopo tem que ser o de quem PEDIU a exportação. Sem isto,
     * um export de vendedor rodaria na fila com escopo vazio — ou pior, amplo demais.
     */
    public function plano(string $recurso, Request $request, User $user): PlanoDeExportacao
    {
        return match ($recurso) {
            'carteira' => $this->carteira($request),
            'leads' => $this->leads($request),
            'orcamentos' => $this->orcamentos($request),
            'pedidos-abertos' => $this->pedidosAbertos($request),
            'pedidos-emitidos' => $this->pedidosEmitidos($request),
            'tabela-precos' => $this->tabelaPrecos($request),
            'equipe' => $this->equipe($request, $user),
            'metas' => $this->metas($request, $user),
            'cadastros-bobina', 'cadastros-etiqueta', 'cadastros-cliente', 'cadastros-lead'
                => $this->cadastros($recurso, $request, $user),

            /*
             * ⚠️ Estoura em vez de cair num default. O recurso vem de um registro do banco
             * quando o job o lê de volta; um valor desconhecido significa código removido
             * sem migração de dado, e gerar "alguma planilha" seria entregar o arquivo
             * errado sob o rótulo certo. Mesmo raciocínio da whitelist de ordenação da
             * Carteira e do `MetaRankingResolver::TIPOS`.
             */
            default => throw new InvalidArgumentException("Recurso de exportação desconhecido: {$recurso}"),
        };
    }

    private function carteira(Request $request): PlanoDeExportacao
    {
        $query = app(CarteiraController::class)->listaQuery($request);

        return new PlanoDeExportacao(
            new CarteiraExport($query, app(ClienteStatusResolver::class)),
            'carteira',
            (clone $query)->count(),
        );
    }

    private function leads(Request $request): PlanoDeExportacao
    {
        $query = app(LeadController::class)->listaQuery($request);

        return new PlanoDeExportacao(new LeadExport($query), 'leads', (clone $query)->count());
    }

    private function orcamentos(Request $request): PlanoDeExportacao
    {
        $query = app(OrcamentoController::class)->baseQuery($request)
            ->with('user:id,name,display_name')
            ->latest();

        return new PlanoDeExportacao(new OrcamentoExport($query), 'orcamentos', (clone $query)->count());
    }

    private function pedidosAbertos(Request $request): PlanoDeExportacao
    {
        $query = app(PedidoController::class)->listaQueryAbertos($request)
            ->with('cliente:id,razao_social,cnpj')
            ->withCount('itens');

        return new PlanoDeExportacao(new PedidoAbertoExport($query), 'pedidos-abertos', (clone $query)->count());
    }

    private function pedidosEmitidos(Request $request): PlanoDeExportacao
    {
        $ano = (int) ($request->integer('ano') ?: now()->year);
        $mes = max(1, min(12, (int) ($request->integer('mes') ?: now()->month)));

        $query = app(PedidoController::class)->listaQueryEmitidos($request)
            ->with('cliente:id,razao_social,cnpj')
            ->withCount('itens');

        // Ano e mês entram no nome porque são filtros ESTRUTURAIS desta tela: dois
        // arquivos "pedidos-emitidos" na pasta de downloads seriam indistinguíveis.
        return new PlanoDeExportacao(
            new PedidoEmitidoExport($query),
            "pedidos-emitidos-{$ano}-{$mes}",
            (clone $query)->count(),
        );
    }

    private function tabelaPrecos(Request $request): PlanoDeExportacao
    {
        $query = app(TabelaPrecoController::class)->listaQuery($request);

        return new PlanoDeExportacao(new TabelaPrecoExport($query), 'tabela-precos', (clone $query)->count());
    }

    private function equipe(Request $request, User $user): PlanoDeExportacao
    {
        $controller = app(EquipeController::class);
        abort_unless($controller->podeExportar($user), 403);

        $query = $controller->queryFiltrada($request, $user);

        return new PlanoDeExportacao(new EquipeExport($query), 'equipe', (clone $query)->count());
    }

    private function metas(Request $request, User $user): PlanoDeExportacao
    {
        $controller = app(MetaController::class);
        abort_unless($controller->podeExportar($user), 403);

        [$linhas, $ano, $mes] = $controller->linhasDoRanking($request, $user);

        return new PlanoDeExportacao(new MetasExport($linhas), "metas-{$ano}-{$mes}", count($linhas));
    }

    private function cadastros(string $recurso, Request $request, User $user): PlanoDeExportacao
    {
        /*
         * 'cadastros-bobina' => 'bobina'. Os quatro tipos são recursos DISTINTOS na
         * central: cada um gera colunas diferentes, e chamar os quatro de "Cadastros"
         * deixaria a listagem ambígua para quem exportou mais de um no mesmo dia.
         */
        $tipo = substr($recurso, strlen('cadastros-'));

        $query = app(CadastroController::class)->queryDoRecurso($tipo, $request, $user);

        return new PlanoDeExportacao(
            new CadastroExport($query, $tipo),
            "cadastros-{$tipo}",
            (clone $query)->count(),
        );
    }
}
