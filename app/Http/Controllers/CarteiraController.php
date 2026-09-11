<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\AgendamentoLigacao;
use App\Models\CarteiraMotivoInatividade;
use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Models\Ligacao;
use App\Models\Pedido;
use App\Models\Segmento;
use App\Models\VendedorPerfil;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Pedidos\StatusPedidoResolver;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Potencial\FamiliaProduto;
use App\Services\Potencial\PotencialCarteiraResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CarteiraController extends Controller
{
    use ExportaPlanilha;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CarteiraAderenciaResolver $aderenciaResolver,
        private readonly ClienteStatusResolver $statusResolver,
        private readonly StatusPedidoResolver $statusPedido,
        private readonly CacheDeAgregacao $cache,
        private readonly DashboardBlocos $blocos,
        private readonly PotencialCarteiraResolver $potencial,
    ) {
    }

    /**
     * KPIs de aderência do topo da Carteira.
     *
     * O bloco mais caro da tela: ~950 ms de SQL no escopo admin (LEFT JOIN em
     * segmentos/segmentos_vendedor sobre as ~90k linhas de clientes). Era o último lugar
     * do sistema rodando essa agregação sem cache, e a razão de /carteira ter sido o pior
     * p50 do teste de carga.
     *
     * ⚠️ SEM FILTRO, ISTO É EXATAMENTE O CARD DO DASHBOARD.
     * `baseQuery()` sem filtros é `scopeQuery()` mais um `select('clientes.*')` — e o
     * CarteiraAderenciaResolver limpa o select logo no início, então a diferença some.
     * Como a pergunta é a mesma, a resposta tem que ser a mesma (Regra de ouro nº 8):
     * delegamos ao bloco do Dashboard e as duas telas passam a dividir a MESMA chave.
     *
     * Isso vale mais que economia de uma query: essa chave já é aquecida pelo job de
     * warming, então a Carteira sem filtro passou a nunca pagar a agregação — de graça,
     * sem precisar aquecer nada novo.
     *
     * Com filtro ativo, cai no cache próprio: chave com data (o resolver classifica por
     * dias desde a última compra, 290/365, a partir de now()) e TTL curto, já que a
     * cardinalidade vem da query string e é ilimitada em teoria.
     *
     * @param  array<string>|null  $codVendedores
     */
    private function aderencia(Request $request, ?array $codVendedores): array
    {
        $escopo = ChaveEscopo::deCodVendedores($codVendedores);
        $assinatura = $this->assinaturaDosFiltros($request);

        if ($assinatura === 'vazio') {
            return $this->blocos->carteiraSegmento($escopo, $codVendedores);
        }

        return $this->cache->lembrarPorMinutos(
            $escopo->paraDoDia('carteira-kpis', ['f' => $assinatura]),
            10,
            fn () => $this->aderenciaResolver->resolver($this->baseQuery($request)),
        );
    }

    /**
     * Opções dos dropdowns de Estado e Segmento.
     *
     * ⚠️ São dois DISTINCT sobre a tabela inteira de clientes (~90k linhas), e dependem
     * SÓ do escopo — nunca dos filtros ativos, senão o dropdown perderia opções conforme
     * o usuário filtra. Por dependerem só do escopo, são perfeitamente cacheáveis, e por
     * mudarem apenas quando o TOTVS traz cliente novo, o TTL pode ser longo.
     *
     * @param  array<string>|null  $codVendedores
     * @return array{estados: mixed, segmentos: mixed}
     */
    private function opcoesDeFiltro(Request $request, ?array $codVendedores): array
    {
        return $this->cache->lembrarPorHoras(
            ChaveEscopo::deCodVendedores($codVendedores)->para('carteira-opcoes'),
            (int) config('perf.ttl_lookup_minutos', 360) / 60,
            fn () => [
                'estados' => $this->scopeQuery($request)
                    ->whereNotNull('estado')->where('estado', '!=', '')
                    ->distinct()->orderBy('estado')->pluck('estado'),
                'segmentos' => Segmento::query()
                    ->whereIn('codigo', $this->scopeQuery($request)
                        ->whereNotNull('cod_segmento')->where('cod_segmento', '!=', '')
                        ->distinct()->pluck('cod_segmento'))
                    ->orderBy('nome')
                    ->get(['codigo', 'nome']),
            ],
        );
    }

    /**
     * Assinatura estável dos filtros ativos, para compor a chave de cache dos KPIs.
     *
     * ⚠️ Sem filtro nenhum devolve a constante 'vazio', e isso é essencial: é o único
     * caso que o warming alcança (as demais combinações vêm da query string e são
     * ilimitadas em teoria). Manter esse caso legível, em vez de virar mais um hash,
     * é o que permite tratá-lo de propósito em `aderencia()`.
     */
    private function assinaturaDosFiltros(Request $request): string
    {
        $filtros = array_filter([
            'busca' => trim((string) $request->string('busca')),
            'estado' => (string) $request->string('estado'),
            'segmento' => (string) $request->string('segmento'),
            'status' => (string) $request->string('status'),
            'aderencia' => (string) $request->string('aderencia'),
            'sem_familia' => (string) $request->string('sem_familia'),
        ], fn (string $v) => $v !== '');

        if ($filtros === []) {
            return 'vazio';
        }

        ksort($filtros);

        return md5(json_encode($filtros));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );
        $codVendedores = $scope['codVendedores'];

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');
        $aderencia = (string) $request->string('aderencia');
        $semFamiliaBruto = (string) $request->string('sem_familia');
        $semFamilia = in_array($semFamiliaBruto, FamiliaProduto::chaves(), true) ? $semFamiliaBruto : '';
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';
        $aba = (string) $request->string('aba') ?: 'clientes';

        $kpis = $this->aderencia($request, $codVendedores);

        /*
         * O total vem de `filtradaQuery()`, sem a camada de ordenação. Continua valendo
         * como separação de responsabilidade — contar não precisa ordenar —, embora o
         * ganho original tenha sumido junto com os joins de grupo/segmento (removidos em
         * 2026-08-29, ver ORDENACOES).
         *
         * ⚠️ `paginaSegura()` limita a profundidade. Sem isso, `?page=3044` custava 2,5 s
         * — não por o OFFSET encarecer aos poucos, mas porque o otimizador do MySQL troca
         * de plano e passa a varrer a tabela inteira com filesort. Ver `paginaSegura()`.
         */
        $agrupado = $this->agrupar($request);

        $clientes = $agrupado
            ? $this->linhasAgrupadas($request, $codVendedores)
            : $this->listaQuery($request)
                ->paginate(perPage: self::POR_PAGINA, total: $this->filtradaQuery($request)->count(), page: $this->paginaSegura($request))
                ->withQueryString();

        $codVendedoresPresentes = $clientes->getCollection()->pluck('cod_vendedor')->filter()->unique()->values();
        $nomesPorCodVendedor = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $clienteIdsNaPagina = $clientes->getCollection()->pluck('id');

        $motivosPorCliente = CarteiraMotivoInatividade::query()
            ->whereIn('cliente_id', $clienteIdsNaPagina)
            ->latest()
            ->get()
            ->groupBy('cliente_id')
            ->map(fn ($grupo) => $grupo->first());

        $hoje = now();

        $nomePorCodigo = Segmento::pluck('nome', 'codigo');

        // Só os grupos que aparecem nesta página — são 2.4k no total, não vale carregar tudo.
        $nomePorGrupo = GrupoCliente::query()
            ->whereIn('codigo', $clientes->getCollection()->pluck('cod_grupo')->filter()->unique())
            ->pluck('nome', 'codigo');

        $clientes->through(function (Cliente $cliente) use ($nomesPorCodVendedor, $motivosPorCliente, $nomePorCodigo, $nomePorGrupo, $hoje) {
            $motivo = $motivosPorCliente->get($cliente->id);

            return [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                /*
                 * Só existem no modo agrupado, e a linha é a da filial-âncora: `loja` é
                 * para onde as ações desta linha apontam (o de-para do Portal Autopel é
                 * `cod_cliente + loja`, então o vendedor precisa poder ver qual é).
                 * `lojas` conta TODAS as lojas do cliente no escopo, `entregas` quantas
                 * delas são endereço de entrega — ver PREFIXOS_ENTREGA.
                 */
                'loja' => $cliente->loja,
                'lojas' => isset($cliente->lojas) ? (int) $cliente->lojas : null,
                'entregas' => isset($cliente->entregas) ? (int) $cliente->entregas : null,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'telefone' => $cliente->telefone,
                'email' => $cliente->email,
                'estado' => $cliente->estado,
                'segmento' => $cliente->cod_segmento ? ($nomePorCodigo[$cliente->cod_segmento] ?? $cliente->cod_segmento) : null,
                'grupo' => $cliente->cod_grupo ? ($nomePorGrupo[$cliente->cod_grupo] ?? $cliente->cod_grupo) : null,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $nomesPorCodVendedor[$cliente->cod_vendedor] ?? $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, $hoje),
                'dataUltimaCompra' => optional($cliente->data_ultima_compra)->format('d/m/Y'),
                /*
                 * Vem da própria linha do cliente (coluna desnormalizada, mantida por
                 * `UltimoContatoSincronizador`). Antes era uma consulta agregada em
                 * `ligacoes` por página; virar coluna eliminou essa consulta E tornou a
                 * ordenação viável — ver a migration `2026_09_02_110000`.
                 */
                'ultimoContato' => $cliente->data_ultimo_contato ? [
                    'data' => $cliente->data_ultimo_contato->format('d/m/Y'),
                    'canal' => $cliente->canal_ultimo_contato,
                ] : null,
                'motivoInatividade' => $motivo ? [
                    'motivo' => $motivo->motivo,
                    'observacao' => $motivo->observacao,
                    'criadoEm' => $motivo->created_at->format('d/m/Y'),
                ] : null,
            ];
        });

        return Inertia::render('Carteira/Index', [
            'role' => $role,
            'aba' => in_array($aba, ['clientes', 'calendario'], true) ? $aba : 'clientes',
            'clientes' => $clientes,
            'kpis' => $kpis,
            /*
             * Prop opcional: só é enviada quando a requisição pede explicitamente
             * (`only: ['agendamentos']`). A aba Clientes, que é onde a maioria das
             * visitas para, deixou de pagar por uma consulta que ia direto pro lixo.
             *
             * ⚠️ Visita completa (F5, ou entrar por /carteira?aba=calendario) NÃO traz
             * prop opcional — é o `onMounted` do Carteira/Index.vue que busca nesse caso.
             */
            'agendamentos' => Inertia::optional(fn () => $this->agendamentosDoEscopo($codVendedores)),
            'filtros' => [
                'busca' => $busca,
                'estado' => $estado,
                'segmento' => $segmento,
                'status' => $status,
                'aderencia' => $aderencia,
                'ordenar' => $ordenar,
                /*
                 * Uma linha por cliente (com as filiais contadas) ou uma por filial.
                 * Enquanto for opt-in por `?agrupar=1`, a prop é o que permite conferir
                 * os dois modos lado a lado; quando virar o padrão, é ela que alimenta o
                 * botão "ver filiais".
                 */
                'agrupado' => $agrupado,
                // Vem do card de Potencial da Carteira do Painel. Fica na prop para a tela
                // poder anunciar o recorte e oferecer o "limpar" — filtro que a pessoa não
                // consegue ver nem desfazer é o que faz a lista parecer quebrada.
                'semFamilia' => $semFamilia,
                'semFamiliaRotulo' => $semFamilia !== '' ? FamiliaProduto::rotuloDe($semFamilia) : null,
                // Quantas EMPRESAS o recorte tem. A tabela lista filiais, então este número
                // é menor que o total da listagem — e é ele que bate com o card do Painel.
                'semFamiliaEmpresas' => $semFamilia !== '' ? count($this->codigosSemFamilia($request) ?? []) : null,
            ],
            'opcoes' => $this->opcoesDeFiltro($request, $codVendedores),
            'visao' => [
                'mostrarSeletor' => in_array($role, ['supervisor', 'admin', 'diretor'], true),
                'supervisores' => in_array($role, ['admin', 'diretor'], true) ? $this->scopeResolver->opcoesSupervisores() : [],
                'vendedores' => in_array($role, ['supervisor', 'admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesVendedores($user, $scope['visaoSupervisor'])
                    : [],
                'visaoSupervisor' => $scope['visaoSupervisor'],
                'visaoVendedor' => $scope['visaoVendedor'],
            ],
        ]);
    }

    /**
     * Enfileira a geração da planilha da Carteira.
     *
     * ⚠️ ESTE ENDPOINT NÃO DEVOLVE ARQUIVO, e a mudança não é estilística.
     * O export completo (escopo admin, ~90k clientes) leva ~95 s e ~540 MB. Atrás do ALB,
     * cujo idle timeout padrão é 60 s, gerar de forma síncrona resultaria em 504 garantido
     * — com o servidor seguindo ocupado por mais 35 s produzindo um arquivo que ninguém
     * receberia. O usuário é avisado pelo sino quando ficar pronto.
     *
     * ⚠️ Desde 2026-09-09 quem decide entre gerar agora e enfileirar é o VOLUME, não a
     * tela: o vendedor com 283 clientes recebe o arquivo na hora, o admin com 92 mil
     * espera o sino. As outras oito exportações passam pelo mesmo caminho.
     */
    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('carteira', $request);
    }

    /** Escopo (cod_vendedor) puro, sem filtros de busca/estado/segmento/status. */
    protected function scopeQuery(Request $request): Builder
    {
        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $query = Cliente::query();
        if ($scope['codVendedores'] !== null) {
            $query->whereIn('clientes.cod_vendedor', $scope['codVendedores']);
        }

        return $query;
    }

    /**
     * O mesmo recorte de `scopeQuery()`, mas a partir de um escopo JÁ RESOLVIDO.
     *
     * Existe porque `scopeQuery()` chama o `DashboardScopeResolver` toda vez, e resolver
     * o escopo do admin custa 6 consultas de roles/perfis do spatie. Quem precisa de mais
     * de uma consulta sob o mesmo escopo — a listagem agrupada precisa de três — resolve
     * uma vez em `index()` e passa o resultado adiante.
     *
     * `null` significa sem restrição (admin/diretor), exatamente como no resolver.
     *
     * @param  array<string>|null  $codVendedores
     */
    protected function comEscopo(?array $codVendedores): Builder
    {
        $query = Cliente::query();

        if ($codVendedores !== null) {
            $query->whereIn('clientes.cod_vendedor', $codVendedores);
        }

        return $query;
    }

    /** scopeQuery() + busca/estado/segmento/status. Sem aderência/ordenação. Usado por index() (lista e KPIs) e exportar(). */
    protected function baseQuery(Request $request): Builder
    {
        $limiteAtivo = $this->statusResolver->limiteAtivo()->toDateString();
        $limiteInativando = $this->statusResolver->limiteInativando()->toDateString();

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');

        $query = $this->scopeQuery($request)->select('clientes.*');

        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('clientes.razao_social', 'like', "%{$busca}%")
                    ->orWhere('clientes.nome_fantasia', 'like', "%{$busca}%")
                    ->orWhere('clientes.cnpj', 'like', "%{$busca}%")
                    ->orWhere('clientes.cod_cliente', 'like', "%{$busca}%");
            });
        }

        if ($estado !== '') {
            $query->where('clientes.estado', $estado);
        }

        if ($segmento !== '') {
            $query->where('clientes.cod_segmento', $segmento);
        }

        $this->aplicarSemFamilia($request, $query);

        match ($status) {
            'ativo' => $query->where('clientes.data_ultima_compra', '>=', $limiteAtivo),
            'inativando' => $query->where('clientes.data_ultima_compra', '<', $limiteAtivo)
                ->where('clientes.data_ultima_compra', '>=', $limiteInativando),
            'inativo' => $query->where(fn ($q) => $q->whereNull('clientes.data_ultima_compra')->orWhere('clientes.data_ultima_compra', '<', $limiteInativando)),
            default => null,
        };

        return $query;
    }

    /**
     * Filtro "clientes ativos que ainda NÃO compram <família>", vindo do card de Potencial
     * da Carteira do Painel. É o que transforma o número do card numa lista de clientes
     * para ligar.
     *
     * ⚠️ Whitelist, não string livre: a família vem da query string e vira o valor
     * comparado contra `produtos.categoria`. `FamiliaProduto::garantir()` estoura em valor
     * desconhecido; aqui um valor inválido simplesmente não filtra nada, porque a tela não
     * deve dar 500 por causa de um link velho.
     *
     * ⚠️ CACHEADO POR DIA, e o motivo não é economia: no código de vendedor extremo
     * (`010002`, sozinho 94% das linhas de `faturamentos`) a consulta leva 30 segundos.
     * Para os 121 vendedores reais ela custa entre 3,7 ms e 72,7 ms, mas quem chega aqui
     * por um link de gestor com drill-down pagaria os 30 s. Com a chave diária isso
     * acontece no máximo uma vez por escopo por dia.
     *
     * ⚠️ O grão diverge de propósito: o card conta EMPRESAS (`cod_cliente`) e esta tela
     * lista FILIAIS. Um código com 5 lojas vira 5 linhas aqui. É consequência de
     * `faturamentos` não guardar `loja` — ver o docblock do PotencialCarteiraResolver — e o
     * card declara isso no rodapé.
     */
    private function aplicarSemFamilia(Request $request, Builder $query): void
    {
        $codigos = $this->codigosSemFamilia($request);

        if ($codigos !== null) {
            $query->whereIn('clientes.cod_cliente', $codigos);
        }
    }

    /**
     * Os códigos do recorte, ou null quando não há recorte de família na requisição.
     *
     * ⚠️ Separado de `aplicarSemFamilia()` porque `index()` também precisa do TAMANHO da
     * lista: o card do Painel conta empresas e esta tela lista filiais, então sem dizer
     * "86 filiais de 40 empresas" o vendedor clica em 40 e encontra 86, sem explicação. A
     * segunda chamada não custa nada — cai no mesmo cache.
     *
     * @return list<string>|null
     */
    private function codigosSemFamilia(Request $request): ?array
    {
        $familia = (string) $request->string('sem_familia');

        if ($familia === '' || ! in_array($familia, FamiliaProduto::chaves(), true)) {
            return null;
        }

        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $chave = ChaveEscopo::deCodVendedores($scope['codVendedores'])
            ->paraDoDia('potencial-codigos', ['familia' => $familia]);

        return $this->cache->lembrar(
            $chave,
            fn () => $this->potencial->codigosSemFamilia($scope['codVendedores'], $familia),
        );
    }

    /**
     * Colunas que a tela deixa ordenar por clique no header.
     *
     * É whitelist de propósito: o campo vem da query string, e concatenar isso
     * direto num orderBy seria injeção de SQL. Nunca trocar por passar o valor
     * cru pro orderBy(), nem "só validar se não tem espaço".
     *
     * `join` marca as duas que ordenam por um nome que mora em outra tabela.
     * Custo medido com 91.293 clientes (escopo admin, sem filtro): coluna direta
     * indexada ~0,5ms; as com join ~156ms, porque o LEFT JOIN força filesort e
     * nenhum índice cobre. Por isso nenhuma das duas é a ordem padrão da tela —
     * são ação explícita do usuário, não custo de todo carregamento.
     *
     * `agregado` é a forma da MESMA ordenação quando a lista está agrupada por cliente
     * (`linhasAgrupadas()`): ali a linha é um `cod_cliente` com N filiais, e "ordenar por
     * estado" precisa de UM valor por grupo. Continua saindo da whitelist, nunca da query
     * string — é a mesma defesa contra injeção, agora dentro de um `orderByRaw`.
     *
     * ⚠️ O agregado é FIXO por campo, não muda com a direção. `nome_desc` ordena pelo
     * `MIN(razao_social)` em ordem decrescente, e não pelo `MAX`. Fazer o agregado seguir
     * a direção daria a impressão de simetria mas tornaria a posição de um cliente
     * imprevisível: ele mudaria de valor de ordenação conforme o sentido do clique.
     */
    private const ORDENACOES = [
        'nome' => ['coluna' => 'clientes.razao_social', 'agregado' => 'MIN(clientes.razao_social)'],
        'vendedor' => ['coluna' => 'clientes.cod_vendedor', 'agregado' => 'MIN(clientes.cod_vendedor)'],
        'estado' => ['coluna' => 'clientes.estado', 'agregado' => 'MIN(clientes.estado)'],
        /*
         * ⚠️ NÃO reintroduzir 'grupo' e 'segmento' aqui.
         *
         * O nome dos dois mora em outra tabela (grupos_cliente / segmentos), então
         * ordenar por eles exige LEFT JOIN, e o join força filesort: medido em produção,
         * 596 ms (grupo) e 603 ms (segmento) no escopo admin, contra 106 ms da ordenação
         * padrão — acima do orçamento de 400 ms da Regra de ouro nº 9.
         *
         * Removidos por decisão do Tony em 2026-08-29: ordenar por essas duas colunas não
         * tem uso real. Quem precisa recortar por grupo ou segmento usa o FILTRO, que é
         * barato porque compara o código na própria tabela `clientes` (138 ms medidos) em
         * vez de ordenar pelo nome que veio do join.
         *
         * Se algum dia voltar a fazer falta, a saída já medida é o "deferred id":
         * ordenar só `id` num subselect e buscar as linhas depois (501 ms → 135 ms em
         * desenvolvimento). Não foi feito porque não compõe bem com `paginate()`.
         */
        // Status é derivado de data_ultima_compra por faixas, então ordenar por
        // um é ordenar pelo outro. Só inverte o sentido: "status asc" é
        // Ativo -> Inativo, que é compra mais RECENTE primeiro.
        'status' => ['coluna' => 'clientes.data_ultima_compra', 'inverter' => true, 'agregado' => 'MAX(clientes.data_ultima_compra)'],
        'ultima_compra' => ['coluna' => 'clientes.data_ultima_compra', 'agregado' => 'MAX(clientes.data_ultima_compra)'],
        /*
         * Ordenável porque `data_ultimo_contato` é coluna INDEXADA da própria
         * `clientes` — 1,2 ms. Não confundir com o caso de 'grupo'/'segmento' acima:
         * ali o valor mora em outra tabela e o join força filesort. Ordenar por
         * `MAX(data_ligacao)` direto em `ligacoes` foi medido em 987 ms e é exatamente
         * o que a desnormalização existe pra evitar.
         */
        'ultimo_contato' => ['coluna' => 'clientes.data_ultimo_contato', 'agregado' => 'MAX(clientes.data_ultimo_contato)'],
    ];

    /** Linhas por página. Vale para a lista plana e para a agrupada. */
    private const POR_PAGINA = 30;

    /**
     * Teto de filiais devolvidas pela linha expansível.
     *
     * Não é paginação: é o reconhecimento de que nenhuma interface ajuda alguém a ler
     * 4.179 lojas (o tamanho real da CAIXA ECONÔMICA FEDERAL num único código). Quem
     * precisa da lista inteira usa o Excel, que continua saindo por filial.
     */
    private const LIMITE_FILIAIS = 100;

    /**
     * Prefixos de `loja` que são ENDEREÇO DE ENTREGA, não filial comercial.
     *
     * ✅ CONFIRMADO PELO ADRIANO (TOTVS) em 2026-09-11: `E` e `X` são endereço de
     * entrega mesmo. Antes disto era inferência nossa a partir dos dados — as 26.828
     * linhas com estes prefixos têm CNPJ idêntico ao da matriz e 98% nunca compraram,
     * porque a compra é lançada na loja comercial. Caso extremo real: a AUTOPASS tem 220
     * linhas na carteira da Sthefany, sendo 2 filiais (CNPJ /0001-40 e /0025-18) e 218
     * pontos de entrega, um por estação de metrô.
     *
     * ⚠️ Prefixo NOVO que o TOTVS venha a criar cai aqui como filial comercial, e nada
     * acusa — a contagem simplesmente para de separar. Se aparecer prefixo estranho na
     * base, é esta lista que precisa saber dele.
     */
    private const PREFIXOS_ENTREGA = ['E', 'X'];

    /** baseQuery() + aderência + ordenação. Usado por index() (lista) e exportar(). */
    public function listaQuery(Request $request): Builder
    {
        return $this->aplicarOrdenacao(
            $this->filtradaQuery($request),
            (string) $request->string('ordenar') ?: 'nome_asc',
        );
    }

    /**
     * baseQuery() + aderência, sem ordenação. Existe separado porque é a query que
     * define QUANTAS linhas a lista tem — o join de ordenação por nome de
     * grupo/segmento não pode entrar nessa conta (ver `index()`).
     */
    protected function filtradaQuery(Request $request): Builder
    {
        $query = $this->baseQuery($request);
        $aderencia = (string) $request->string('aderencia');

        if ($aderencia !== '') {
            $temSegmentoDefinido = fn ($q) => $q->selectRaw(1)
                ->from('segmentos_vendedor as sv2')
                ->whereColumn('sv2.cod_vendedor', 'clientes.cod_vendedor');

            if ($aderencia === 'sem_segmento') {
                $query->whereNotExists($temSegmentoDefinido);
            } else {
                $query->whereExists($temSegmentoDefinido)
                    ->leftJoin('segmentos', 'segmentos.codigo', '=', 'clientes.cod_segmento')
                    ->leftJoin('segmentos_vendedor', function ($join) {
                        $join->on('segmentos_vendedor.cod_vendedor', '=', 'clientes.cod_vendedor')
                            ->on('segmentos_vendedor.segmento_id', '=', 'segmentos.id');
                    });

                $aderencia === 'dentro'
                    ? $query->whereNotNull('segmentos_vendedor.id')
                    : $query->whereNull('segmentos_vendedor.id');
            }
        }

        return $query;
    }

    /**
     * `ordenar` chega como "<campo>_<asc|desc>" (ex.: "grupo_desc"). Campo fora da
     * whitelist ou direção inválida cai no padrão, sem erro pro usuário.
     */
    /**
     * As filiais de um cliente, para a linha expansível da Carteira agrupada.
     *
     * ⚠️ O ESCOPO É O `WHERE`, não um `abort()` depois. `autorizarCliente()` não serve
     * aqui: ela recebe um `Cliente` (uma filial) e este endpoint recebe um `cod_cliente`,
     * que pode ter filiais de mais de um vendedor — 3.964 casos na base. Filtrando no
     * `WHERE`, o vendedor vê as filiais DELE e as dos outros nem chegam a existir na
     * resposta. Autorizar "o cliente" e depois listar tudo vazaria a carteira alheia.
     *
     * 404 quando não sobra nenhuma: ou o código não existe, ou não é de quem perguntou —
     * e as duas respostas devem ser indistinguíveis de fora.
     *
     * ⚠️ Tem TETO. A CAIXA ECONÔMICA FEDERAL tem 4.179 lojas num só código; sem limite,
     * abrir aquela linha despejaria 4.179 registros no navegador do vendedor. O total
     * real vai junto para a tela poder dizer que está mostrando uma parte.
     */
    public function filiais(Request $request, string $codCliente): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request->user(), null, null);

        $query = $this->comEscopo($scope['codVendedores'])
            ->where('clientes.cod_cliente', $codCliente);

        $total = (clone $query)->count();

        abort_if($total === 0, 404);

        $entrega = "LEFT(clientes.loja, 1) IN ('".implode("','", self::PREFIXOS_ENTREGA)."')";
        $hoje = now();

        $filiais = $query
            // Mesma ordem da escolha da âncora: comercial primeiro, depois por loja.
            // A âncora é sempre a primeira linha da expansão, e isso não é coincidência.
            ->orderByRaw("IF({$entrega}, 1, 0)")
            ->orderBy('clientes.loja')
            ->limit(self::LIMITE_FILIAIS)
            ->get()
            ->map(fn (Cliente $filial) => [
                'id' => $filial->id,
                'loja' => $filial->loja,
                'razaoSocial' => $filial->razao_social,
                'cnpj' => $filial->cnpj,
                'telefone' => $filial->telefone,
                'email' => $filial->email,
                'estado' => $filial->estado,
                'ehEntrega' => in_array(mb_substr((string) $filial->loja, 0, 1), self::PREFIXOS_ENTREGA, true),
                'status' => $this->statusResolver->statusPara($filial->data_ultima_compra, $hoje),
                'dataUltimaCompra' => optional($filial->data_ultima_compra)->format('d/m/Y'),
            ]);

        return response()->json([
            'codCliente' => $codCliente,
            'total' => $total,
            'mostrando' => $filiais->count(),
            'filiais' => $filiais,
        ]);
    }

    /**
     * A lista está agrupada por cliente (uma linha por `cod_cliente`) nesta requisição?
     *
     * ⚠️ O PADRÃO É AGRUPADO, e quem desliga pede `?agrupar=0`. A inversão é deliberada:
     * quem mais sofre com a lista por filial é justamente quem nunca acharia um botão
     * para ligá-la — o Paulo tem 14.304 linhas, 477 páginas, e o teto de
     * `perf.max_paginas` deixa 94% da carteira dele inalcançável por clique. Agrupado
     * são 22 páginas e ele vê tudo.
     *
     * Comparar valor contra `'0'` e não o contrário mantém o link antigo `?agrupar=1`
     * funcionando, e faz qualquer valor inesperado cair no padrão em vez de derrubar a
     * tela.
     */
    protected function agrupar(Request $request): bool
    {
        return $request->string('agrupar')->value() !== '0';
    }

    /**
     * A lista agrupada por cliente, paginada — uma linha por `cod_cliente`, com as
     * filiais resumidas em contagem (a expansão é a Etapa 2).
     *
     * São TRÊS passagens, e a separação é o que mantém a tela dentro do orçamento:
     *
     *   1. quais 30 clientes entram na página (`GROUP BY` + `ORDER BY <agregado>`)
     *   2. filiais, entregas, datas e âncora DESSES 30
     *   3. a linha completa da filial-âncora, para o `through()` de `index()` seguir igual
     *
     * ⚠️ Uma passagem só NÃO funciona: `baseQuery()` faz `select('clientes.*')` e o
     * MySQL 8 recusa isso sob `GROUP BY` (`only_full_group_by`). Trocar cada coluna por
     * `ANY_VALUE()` compilaria e devolveria valores de filiais diferentes na mesma linha.
     *
     * Medido em dev (92.209 linhas / 39.692 clientes), página completa: admin sem filtro
     * 362 ms, vendedor com 14.304 linhas 280 ms, vendedor típico 30 ms. Depende dos dois
     * índices da migration `2026_09_11_110000` — sem eles o admin passa de 700 ms.
     */
    protected function linhasAgrupadas(Request $request, ?array $codVendedores): LengthAwarePaginator
    {
        $pagina = $this->paginaSegura($request);
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';

        $codigos = $this->codigosDaPagina($request, $pagina, $ordenar);
        $resumo = $this->resumoDosCodigos($codVendedores, $codigos);
        $ancoras = $this->ancorasDoResumo($codVendedores, $resumo);

        /*
         * A ordem de `$codigos` é a que o passo 1 decidiu; `$resumo` e `$ancoras` vêm
         * chaveados, então percorrer `$codigos` é o que preserva a ordenação pedida.
         * Um cliente sem âncora seria cadastro corrompido (grupo existe, nenhuma loja) —
         * cai fora em vez de virar linha vazia.
         */
        $linhas = $codigos
            ->map(function (string $codigo) use ($resumo, $ancoras) {
                $dados = $resumo->get($codigo);

                if (! $dados) {
                    return null;
                }

                $cliente = $ancoras->get($codigo);

                if (! $cliente) {
                    return null;
                }

                /*
                 * As datas do GRUPO substituem as da âncora: a empresa comprou quando
                 * QUALQUER filial comprou. Sobrescrever aqui é o que deixa o `through()`
                 * de `index()` — inclusive o `statusResolver` e a coluna "Último contato"
                 * — funcionar sem saber que existe agrupamento.
                 *
                 * 🚨 ESTES MODELS SÃO DESCARTÁVEIS, SÓ PARA RENDERIZAR. Nunca chamar
                 * `save()` neles: `data_ultima_compra` é `$fillable` e está com o valor
                 * do GRUPO, não o da linha — salvar gravaria em UMA filial a data de
                 * outra, corrompendo o espelho do TOTVS em silêncio. `lojas` e `entregas`
                 * nem coluna são, e um `save()` estouraria com "column not found".
                 * A Carteira é só leitura (Regra de ouro nº 4), então hoje não há esse
                 * caminho — isto é aviso para quem for acrescentar um.
                 */
                $cliente->data_ultima_compra = $dados->uc;
                $cliente->data_ultimo_contato = $dados->uct;

                $cliente->lojas = (int) $dados->total_lojas;
                $cliente->entregas = (int) $dados->entregas;

                return $cliente;
            })
            ->filter()
            ->values();

        return new LengthAwarePaginator(
            $linhas,
            $this->totalAgrupado($request, $codVendedores),
            self::POR_PAGINA,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * Passo 1: os `cod_cliente` desta página, já na ordem pedida.
     *
     * ⚠️ `select([])` antes do select próprio limpa o `select('clientes.*')` herdado de
     * `baseQuery()` — mesmo truque do `CarteiraAderenciaResolver`, e sem ele o
     * `only_full_group_by` derruba a consulta.
     *
     * Efeito colateral bem-vindo: o `leftJoin` do filtro de aderência pode multiplicar
     * linhas, e o `GROUP BY` torna isso inofensivo.
     *
     * @return Collection<int, string>
     */
    protected function codigosDaPagina(Request $request, int $pagina, string $ordenar): Collection
    {
        [$agregado, $direcao] = $this->ordenacaoAgrupada($ordenar);

        return $this->filtradaQuery($request)
            ->select([])
            ->select('clientes.cod_cliente')
            ->groupBy('clientes.cod_cliente')
            ->orderByRaw("{$agregado} {$direcao}")
            /*
             * Desempate estável, pelo mesmo motivo do `orderBy('clientes.id')` da lista
             * plana: sem ele, páginas diferentes repetem ou pulam cliente quando muitos
             * grupos empatam no agregado (estado, status). Aqui a chave é `cod_cliente`
             * porque `id` é de filial e não identifica a linha agrupada.
             */
            ->orderBy('clientes.cod_cliente', $direcao)
            ->forPage($pagina, self::POR_PAGINA)
            ->pluck('cod_cliente');
    }

    /**
     * Passo 2: filiais, entregas, datas do grupo e filial-âncora dos códigos da página.
     *
     * ⚠️ Parte de `scopeQuery()`, NÃO de `baseQuery()`, e a diferença é deliberada nos
     * dois sentidos:
     *
     *   - o ESCOPO DE VENDEDOR tem que estar aqui. Sem ele, um `cod_cliente` dividido
     *     entre vendedores (3.964 casos, 39% das linhas da base) contaria filiais que
     *     pertencem a outra pessoa — vazamento de escopo disfarçado de contagem.
     *   - os FILTROS DE TELA não podem estar. "3 filiais" é quantas a pessoa tem daquele
     *     cliente na carteira, não quantas sobreviveram ao filtro de estado. A linha
     *     expansível mostra as mesmas 3, então os dois números batem.
     *
     * ⚠️ A âncora é a MENOR LOJA COMERCIAL, com a menor loja qualquer como reserva —
     * determinística, e é ela que recebe as ações da linha (ligar, orçamento). Se fosse
     * arbitrária, o orçamento criado pela linha agrupada apontaria para uma filial
     * diferente a cada carregamento, e o de-para do Portal Autopel é `cod_cliente + loja`.
     *
     * ⚠️ Nada de `GROUP_CONCAT` para escolher a âncora: `group_concat_max_len` é 1024 por
     * padrão e truncaria em silêncio a CAIXA ECONÔMICA FEDERAL (4.179 lojas num só
     * código), devolvendo a filial errada sem erro nenhum.
     *
     * ⚠️ Recebe `$codVendedores` JÁ RESOLVIDO em vez de chamar `scopeQuery()`. Não é
     * estilo: resolver o escopo do perfil admin custa 6 consultas de roles do spatie, e
     * com os três passos resolvendo por conta própria a página fazia isso três vezes.
     * Medido: o passo 3 aparecia com 208 ms sendo que a consulta dele leva 7,5 ms — todo
     * o resto era o escopo sendo redescoberto. Mesma lição de 2026-09-04, quando
     * `codigosDoEscopo()` virou público pelo mesmo motivo.
     *
     * @param  array<string>|null  $codVendedores
     * @param  Collection<int, string>  $codigos
     */
    protected function resumoDosCodigos(?array $codVendedores, Collection $codigos): Collection
    {
        if ($codigos->isEmpty()) {
            return collect();
        }

        $entrega = "LEFT(clientes.loja, 1) IN ('".implode("','", self::PREFIXOS_ENTREGA)."')";

        return $this->comEscopo($codVendedores)
            ->select([])
            ->whereIn('clientes.cod_cliente', $codigos)
            ->groupBy('clientes.cod_cliente')
            ->selectRaw('clientes.cod_cliente')
            ->selectRaw('COUNT(*) as total_lojas')
            ->selectRaw("SUM({$entrega}) as entregas")
            ->selectRaw('MAX(clientes.data_ultima_compra) as uc')
            ->selectRaw('MAX(clientes.data_ultimo_contato) as uct')
            /*
             * O ID da âncora, não a loja: o passo 3 busca por chave primária, e isso
             * importa muito mais do que parece (ver o docblock de `ancorasDoResumo()`).
             *
             * O truque é ordenar dentro do grupo por uma string montada — comercial antes
             * de entrega (`0` antes de `1`), depois menor loja — e carregar o `id` no fim
             * dela, recuperando-o com SUBSTRING_INDEX. `MIN()` sobre string não tem o
             * limite de tamanho do GROUP_CONCAT, que truncaria em silêncio o cliente com
             * 4.179 lojas. O LPAD existe para o caso de lojas com larguras diferentes.
             */
            ->selectRaw("SUBSTRING_INDEX(MIN(CONCAT(IF({$entrega}, '1', '0'), LPAD(clientes.loja, 10, '0'), ':', clientes.id)), ':', -1) as ancora_id")
            ->get()
            ->keyBy('cod_cliente');
    }

    /**
     * Passo 3: a linha real de cada filial-âncora, chaveada por `cod_cliente`.
     *
     * 🚨 BUSCA POR CHAVE PRIMÁRIA, E ISSO NÃO É PREFERÊNCIA DE ESTILO — é 113x.
     *
     * A primeira versão filtrava por `whereIn(cod_cliente, 30) + whereIn(loja, 5)`, que
     * o índice único `(cod_cliente, loja)` deveria servir de graça. E serve: rodada com
     * os valores ESCRITOS NA CONSULTA ela custa 5,3 ms, `type=range`, 150 linhas lidas.
     * Só que o Laravel manda os valores como bindings de prepared statement, e aí a mesma
     * consulta passa a custar 234 ms — o otimizador não enxerga os valores na hora de
     * montar o plano e desiste do range scan. Medido no mesmo processo, alternando:
     *
     *   literal na consulta ....................   5,3 ms
     *   prepared com bindings .................. 233,8 ms
     *   Eloquent whereIn (o que estava aqui) ... 213,9 ms
     *   whereIn na PK (esta versão) ............   1,7 ms
     *
     * Buscar por `id` escapa disso porque a PK é única: 30 valores, 30 linhas, e nenhum
     * plano alternativo para o otimizador considerar. Era o passo mais barato do desenho
     * e tinha virado o mais caro da página inteira, sem que o EXPLAIN acusasse nada — o
     * EXPLAIN roda com literais, então mostrava o plano bom o tempo todo.
     *
     * ⚠️ Lição para o resto do projeto: `EXPLAIN` de uma consulta com `IN` grande NÃO
     * prova o custo do que o Eloquent vai executar. Medir pelo caminho real, sempre.
     *
     * O escopo é reaplicado por segurança, embora os ids já venham de `resumoDosCodigos()`,
     * que é escopado: filtro de autorização em camada dupla não é redundância cara aqui
     * (a PK já reduziu a busca a 30 linhas).
     *
     * @param  array<string>|null  $codVendedores
     */
    protected function ancorasDoResumo(?array $codVendedores, Collection $resumo): Collection
    {
        if ($resumo->isEmpty()) {
            return collect();
        }

        return $this->comEscopo($codVendedores)
            ->select('clientes.*')
            ->whereIntegerInRaw('clientes.id', $resumo->pluck('ancora_id')->all())
            ->get()
            ->keyBy('cod_cliente');
    }

    /**
     * Quantos CLIENTES a lista tem (não quantas filiais).
     *
     * ⚠️ `->count()` puro sobre uma query com `GROUP BY` devolve o tamanho do primeiro
     * grupo, não o número de grupos — silenciosamente, e o número vai direto para a
     * paginação. Daí `distinct()->count('clientes.cod_cliente')`, que também neutraliza
     * a multiplicação de linhas do `leftJoin` do filtro de aderência.
     *
     * ⚠️ CACHEADO, e não por economia: sozinho ele custa 87 ms no escopo empresa, e era
     * o que punha a página em 419 ms — 5% acima do orçamento de 400 ms da Regra de ouro
     * nº 9. Cachear é o remédio certo aqui porque o total NÃO MUDA ENTRE PÁGINAS: sem
     * cache, folhear a lista repagaria a mesma contagem a cada clique.
     *
     * A chave tem que carregar a assinatura dos filtros. Sem ela, filtrar por estado
     * mostraria o total da lista inteira — número errado sob o rótulo certo, que é
     * exatamente o tipo de divergência que a tela não pode ter.
     *
     * @param  array<string>|null  $codVendedores
     */
    protected function totalAgrupado(Request $request, ?array $codVendedores): int
    {
        $chave = ChaveEscopo::deCodVendedores($codVendedores)
            ->paraDoDia('carteira-total-agrupado', ['f' => $this->assinaturaDosFiltros($request)]);

        return (int) $this->cache->lembrarPorMinutos(
            $chave,
            10,
            fn () => $this->filtradaQuery($request)->distinct()->count('clientes.cod_cliente'),
        );
    }

    /**
     * Traduz `ordenar` para [expressão agregada, direção], sempre pela whitelist.
     *
     * @return array{0: string, 1: string}
     */
    protected function ordenacaoAgrupada(string $ordenar): array
    {
        preg_match('/^(.*)_(asc|desc)$/', $ordenar, $partes);

        $campo = $partes[1] ?? 'nome';
        $direcao = $partes[2] ?? 'asc';

        $config = self::ORDENACOES[$campo] ?? self::ORDENACOES['nome'];

        if ($config['inverter'] ?? false) {
            $direcao = $direcao === 'asc' ? 'desc' : 'asc';
        }

        return [$config['agregado'], $direcao];
    }

    /**
     * Limita a profundidade da paginação.
     *
     * ⚠️ A causa NÃO é o OFFSET encarecendo aos poucos — foi o que supus, e o EXPLAIN
     * desmentiu. É uma troca de plano do otimizador do MySQL, e ela é um penhasco:
     *
     *   página 30 (offset  870) → type=index, lê    900 linhas →    96 ms
     *   página 40 (offset 1170) → type=ALL + filesort, lê 90.770 → 1.084 ms
     *
     * Páginas 1 a 37 ficam todas entre 90 e 150 ms; da 40 em diante o otimizador conclui
     * que varrer a tabela inteira sai mais barato que caminhar o índice. Sem teto, a
     * última página (3.044, alcançável num clique) custava 2,5 s — acima dos 2 s que a
     * Regra de ouro nº 9 manda tornar assíncrono.
     *
     * O teto e o raciocínio completo estão em `config/perf.php`, chave `max_paginas`.
     *
     * ⚠️ Isto é um TETO, não uma correção. Quem precisa chegar longe na lista deveria
     * usar busca ou filtro, que continuam baratos (138-244 ms medidos). A correção de
     * verdade seria paginação por cursor (keyset), que não tem esse custo — mas ela
     * remove a navegação por número de página, que é o que a tela usa hoje.
     */
    protected function paginaSegura(Request $request): int
    {
        $pedida = max(1, (int) $request->integer('page', 1));

        return min($pedida, (int) config('perf.max_paginas', 40));
    }

    protected function aplicarOrdenacao(Builder $query, string $ordenar): Builder
    {
        preg_match('/^(.*)_(asc|desc)$/', $ordenar, $partes);

        $campo = $partes[1] ?? 'nome';
        $direcao = $partes[2] ?? 'asc';

        $config = self::ORDENACOES[$campo] ?? self::ORDENACOES['nome'];

        if (($config['inverter'] ?? false)) {
            $direcao = $direcao === 'asc' ? 'desc' : 'asc';
        }

        /*
         * ⚠️ Sem `data_ultima_compra IS NULL` na frente, que é como isto era escrito.
         * Aquilo põe uma EXPRESSÃO como primeira chave de ordenação, e expressão não
         * usa índice: media 80ms mesmo com o índice em `data_ultima_compra`, contra
         * 0,34ms sem ela (mesma armadilha da Regra de ouro nº 6, agora no ORDER BY).
         *
         * O MySQL já entrega o que aquilo queria no DESC: NULL é o menor valor, então
         * "nunca comprou" vai pro fim sozinho. No ASC os NULLs vêm primeiro — mudança
         * de comportamento assumida, e que faz sentido: quem nunca comprou é o mais
         * frio de todos. MySQL 8 não tem NULLS LAST, então manter os dois com NULL no
         * fim custaria o filesort de volta.
         */
        return $query->orderBy($config['coluna'], $direcao)
            /*
             * Desempate estável: sem isso, páginas diferentes podem repetir/pular
             * registros quando a coluna ordenada tem muitos valores iguais (estado,
             * grupo, status).
             *
             * ⚠️ A direção do desempate TEM que acompanhar a da coluna. Um índice
             * secundário do InnoDB já carrega a PK dentro dele, mas na ordem dele:
             * ordenar "coluna DESC, id ASC" mistura os sentidos, nenhum índice
             * atende, e volta o `type: ALL` + filesort (medido: 80ms contra 0,39ms).
             * Fixar `->orderBy('clientes.id')` aqui reintroduz o problema.
             */
            ->orderBy('clientes.id', $direcao);
    }

    public function detalhes(Request $request, Cliente $cliente): Response
    {
        $this->autorizarCliente($request, $cliente);

        $pedidosBase = Pedido::query()->where('cliente_id', $cliente->id);

        $qtdPedidos = (clone $pedidosBase)->count();
        $volumeTotal = (float) (clone $pedidosBase)->sum('valor_total');
        $ticketMedio = $qtdPedidos > 0 ? round($volumeTotal / $qtdPedidos, 2) : 0.0;

        $ultimaFaturamento = (clone $pedidosBase)->whereNotNull('data_faturamento')->max('data_faturamento');
        $ultimaCompraFormatada = optional($cliente->data_ultima_compra)->format('d/m/Y')
            ?? ($ultimaFaturamento ? \Illuminate\Support\Carbon::parse($ultimaFaturamento)->format('d/m/Y') : null);

        $pedidos = Pedido::query()
            ->where('cliente_id', $cliente->id)
            // Os itens vêm junto (uma query a mais pra página inteira, não uma por
            // pedido) porque a linha é expansível. São só 20 pedidos por página —
            // o cliente com mais pedidos da base tem 32 no total.
            ->with('itens:id,pedido_id,cod_produto,descricao,nota_fiscal,quantidade,quantidade_liberada,peso_liquido,valor_unitario,valor_total')
            ->withCount('itens')
            ->orderByDesc('data_pedido')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Pedido $p) => [
                'id' => $p->id,
                'numeroPedido' => $p->numero_pedido,
                'dataPedido' => optional($p->data_pedido)->format('d/m/Y'),
                'dataFaturamento' => optional($p->data_faturamento)->format('d/m/Y'),
                ...$this->statusPedido->paraTela($p),
                'valorTotal' => (float) $p->valor_total,
                'itensCount' => $p->itens_count,
                'emAberto' => $p->data_faturamento === null,
                // Campos fiscais e logísticos: vêm do RLT 232 e ficam nulos até o
                // relatório ser ajustado. A tela só mostra o que tiver valor, então
                // enviar null aqui não polui nada.
                'rps' => $p->rps,
                'tipoFaturamento' => $p->tipo_faturamento,
                'condicaoPagamento' => $p->condicao_pagamento,
                'dataEntregaPrevista' => optional($p->data_entrega_prevista)->format('d/m/Y'),
                'dataPcp' => optional($p->data_pcp)->format('d/m/Y'),
                'carga' => $p->carga,
                // Peso do pedido = soma das linhas. `peso_liquido` é UNITÁRIO (ver a
                // migration 120000), então cada linha pesa peso × quantidade.
                'pesoTotal' => $p->itens->contains(fn ($i) => $i->peso_liquido !== null)
                    ? (float) $p->itens->sum(fn ($i) => (float) ($i->peso_liquido ?? 0) * (float) $i->quantidade)
                    : null,
                'itens' => $p->itens->map(fn ($i) => [
                    'id' => $i->id,
                    'codProduto' => $i->cod_produto,
                    'descricao' => $i->descricao,
                    'notaFiscal' => $i->nota_fiscal,
                    'quantidade' => (float) $i->quantidade,
                    'quantidadeLiberada' => $i->quantidade_liberada !== null ? (float) $i->quantidade_liberada : null,
                    'pesoLiquido' => $i->peso_liquido !== null ? (float) $i->peso_liquido : null,
                    'pesoLinha' => $i->peso_liquido !== null
                        ? (float) $i->peso_liquido * (float) $i->quantidade
                        : null,
                    'valorUnitario' => (float) $i->valor_unitario,
                    'valorTotal' => (float) $i->valor_total,
                ])->all(),
            ]);

        $vendedorNome = VendedorPerfil::query()
            ->where('cod_vendedor', $cliente->cod_vendedor)
            ->with('user:id,name,display_name')
            ->first();

        return Inertia::render('Carteira/Detalhes', [
            'cliente' => [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                'loja' => $cliente->loja,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'estado' => $cliente->estado,
                'cep' => $cliente->cep,
                'telefone' => $cliente->telefone,
                'email' => $cliente->email,
                'segmento' => $cliente->cod_segmento ? (Segmento::where('codigo', $cliente->cod_segmento)->value('nome') ?? $cliente->cod_segmento) : null,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $vendedorNome?->user?->display_name ?: $vendedorNome?->user?->name ?: $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, now()),
                'dataUltimaCompra' => $ultimaCompraFormatada,
            ],
            'kpis' => [
                'pedidos' => $qtdPedidos,
                'volumeTotal' => $volumeTotal,
                'ticketMedio' => $ticketMedio,
                'ultimaCompra' => $ultimaCompraFormatada ?? 'Nunca',
            ],
            'pedidos' => $pedidos,
        ]);
    }

    public function registrarMotivoInatividade(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarCliente($request, $cliente);

        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        CarteiraMotivoInatividade::create([
            'cliente_id' => $cliente->id,
            'motivo' => $data['motivo'],
            'observacao' => $data['observacao'] ?? null,
            'criado_por_id' => $request->user()->id,
        ]);

        return back();
    }

    /**
     * Registra um contato com o cliente — telefone, WhatsApp, e-mail ou presencial.
     *
     * ⚠️ O canal vem do front e NÃO é confiável: `Rule::in` contra a constante do
     * model é o que impede um valor arbitrário de chegar ao enum do MySQL. A rota
     * continua se chamando `carteira.ligacao` de propósito (link e teste antigos
     * seguem válidos); o que mudou é que a ligação virou um caso de contato.
     */
    public function registrarLigacao(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarCliente($request, $cliente);

        $tipo = $request->validate([
            'tipo' => ['nullable', Rule::in(Ligacao::TIPOS_CONTATO)],
        ])['tipo'] ?? 'telefonica';

        Ligacao::create([
            'usuario_id' => $request->user()->id,
            'cliente_id' => $cliente->id,
            'cliente_nome' => $cliente->razao_social,
            'tipo_contato' => $tipo,
            'status' => 'finalizada',
            'data_ligacao' => now(),
        ]);

        return back();
    }

    public function registrarAgendamento(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizarCliente($request, $cliente);

        $data = $request->validate([
            'data_agendamento' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        AgendamentoLigacao::create([
            'cliente_id' => $cliente->id,
            'user_id' => $request->user()->id,
            'data_agendamento' => $data['data_agendamento'],
            'observacao' => $data['observacao'] ?? null,
            'status' => 'agendado',
        ]);

        return back();
    }

    public function atualizarAgendamento(Request $request, AgendamentoLigacao $agendamento): RedirectResponse
    {
        $agendamento->load('cliente');
        if ($agendamento->cliente) {
            $this->autorizarCliente($request, $agendamento->cliente);
        }

        $data = $request->validate([
            'status' => ['required', 'in:agendado,realizado,cancelado'],
        ]);

        $agendamento->update(['status' => $data['status']]);

        return back();
    }

    private function autorizarCliente(Request $request, Cliente $cliente): void
    {
        $scope = $this->scopeResolver->resolve($request->user(), null, null);

        if ($scope['codVendedores'] !== null && ! in_array($cliente->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return array<int, array<string, mixed>>
     */
    private function agendamentosDoEscopo(?array $codVendedores): array
    {
        $query = AgendamentoLigacao::query()
            ->with(['cliente:id,razao_social,cnpj,telefone,cod_vendedor', 'user:id,name,display_name'])
            // Janela de -1 a +3 meses (era -3/+6) com teto de 500. O calendário mostra um
            // mês por vez; trazer meio ano de cada lado era payload que ninguém abria.
            ->whereBetween('data_agendamento', [now()->subMonth()->startOfMonth(), now()->addMonths(3)->endOfMonth()])
            ->orderBy('data_agendamento')
            ->limit(500);

        if ($codVendedores !== null) {
            // Subquery IN em vez de whereHas: o whereHas gera um EXISTS correlacionado,
            // avaliado por linha de agendamento. A subquery resolve a lista de clientes
            // uma vez só e tem plano de execução mais previsível.
            $query->whereIn(
                'cliente_id',
                Cliente::query()->select('id')->whereIn('cod_vendedor', $codVendedores),
            );
        }

        return $query->get()->map(fn (AgendamentoLigacao $a) => [
            'id' => $a->id,
            'dataAgendamento' => $a->data_agendamento->toIso8601String(),
            'dataLabel' => $a->data_agendamento->format('d/m/Y H:i'),
            'dia' => $a->data_agendamento->format('Y-m-d'),
            'hora' => $a->data_agendamento->format('H:i'),
            'observacao' => $a->observacao,
            'status' => $a->status,
            'clienteId' => $a->cliente_id,
            'clienteNome' => $a->cliente?->razao_social ?? '—',
            'clienteCnpj' => $a->cliente?->cnpj,
            'autor' => $a->user?->display_name ?: $a->user?->name,
        ])->values()->all();
    }
}
