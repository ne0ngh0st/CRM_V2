<?php

namespace App\Http\Controllers\VisaoDiretor;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use App\Services\VisaoDiretor\BuscaDeVinculo;
use App\Services\VisaoDiretor\BuscaNasRedes;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\ContaDoLead;
use App\Services\VisaoDiretor\LeadDaConta;
use App\Services\VisaoDiretor\AbasDaPlanilha;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use App\Services\VisaoDiretor\TermoDeBusca;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão Diretor → Maiores por Segmento. Quem entra é decidido pelo gate do grupo de rotas
 * (`can:ver-visao-diretor`), não aqui — ver docs/visao-diretor.md.
 *
 * O controller só monta resposta e valida escrita. Os números vêm do
 * `MaioresPorSegmentoResolver`; "quais clientes são desta conta" vem do `ClientesDaConta`.
 */
class MaioresPorSegmentoController extends Controller
{
    use ExportaPlanilha;

    /** Quantos clientes a linha expandida lista. O resto está a um clique, na Carteira. */
    private const LIMITE_CLIENTES_EXPANDIDOS = 50;

    public function __construct(
        private readonly MaioresPorSegmentoResolver $resolver,
        private readonly ClientesDaConta $clientesDaConta,
    ) {
    }

    public function index(Request $request, LeadDaConta $leadDaConta, ContaDoLead $contaDoLead, UniversoDoSegmento $universo): Response
    {
        $filtros = $this->filtros($request);

        return Inertia::render('VisaoDiretor/MaioresPorSegmento', [
            // Uma aba por segmento: os sete com redes primeiro, na ordem da planilha.
            'abas' => $this->abas($universo),
            /*
             * A tabela do segmento aberto: redes, depois todos os clientes e leads, uma
             * paginação só (decisão do Tony, 2026-10-13). Trocar de aba busca só esta prop.
             * No Resumo, nada.
             */
            'universo' => function () use ($filtros, $universo, $request) {
                if ($filtros['segmento'] === '') {
                    return null;
                }

                // Os filtros do topo (status, UF, busca) valem para a tabela inteira.
                $lista = $universo->lista($filtros['segmento'], [...$filtros, 'pagina' => max(1, $request->integer('pagina', 1))]);
                $lista['linhas'] = $lista['linhas']
                    ->withPath(route('visao-diretor.maiores.index'))
                    ->withQueryString()
                    ->toArray();

                return $lista;
            },
            /*
             * Resumo (quadro por segmento, KPIs) e o cabeçalho de cada aba. As linhas que a
             * tabela do segmento mostra vêm de `universo`.
             */
            // Closure: paginar ou filtrar a tabela (`only: universo`) não paga a agregação
            // das ~580 contas de todos os segmentos (~470 ms em dev).
            'dados' => fn () => $this->resolver->resolver([...$filtros, 'segmento' => '']),
            'filtros' => $filtros,
            // Quem pode receber o lead de uma conta-alvo (botão "Gerar lead").
            'responsaveisLead' => $leadDaConta->responsaveis(),
            // Leads da prospecção que o import achou parecidos com uma rede. Esperam decisão.
            'sugestoesLeads' => $contaDoLead->sugestoesPendentes(),
            'segmentosDisponiveis' => Segmento::query()->orderBy('nome')->get(['id', 'codigo', 'nome']),
        ]);
    }

    public function confirmarSugestao(Lead $lead, ContaDoLead $contaDoLead): RedirectResponse
    {
        $contaDoLead->confirmar($lead);

        return back();
    }

    public function recusarSugestao(Lead $lead, ContaDoLead $contaDoLead): RedirectResponse
    {
        $contaDoLead->recusar($lead);

        return back();
    }

    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('maiores-por-segmento', $request);
    }

    /** "Clientes e leads" do segmento aberto, com os filtros da lista. Mais de 2.500 linhas vai para a fila. */
    public function exportarClientes(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('clientes-leads-segmento', $request);
    }

    public function buscarVinculo(Request $request, BuscaDeVinculo $busca): JsonResponse
    {
        return response()->json($busca->buscar((string) $request->string('q')));
    }

    /**
     * Os clientes de uma conta, para a linha expandida: uma linha por `cod_cliente`, com
     * a filial-âncora (menor loja) para o link da ficha.
     *
     * ⚠️ Parte de `ClientesDaConta::filiais()`, a mesma definição da contagem — a lista
     * expandida não pode mostrar clientes que a coluna "Clientes" não contou.
     */
    public function clientes(
        Request $request,
        ContaEstrategica $conta,
        ClienteStatusResolver $status,
        NomeVendedorResolver $nomeVendedor,
    ): JsonResponse {
        $grupos = $this->clientesDaConta->filiais($conta->id)
            ->select([])
            ->selectRaw('clientes.cod_cliente')
            ->selectRaw('COUNT(*) as lojas')
            ->selectRaw('MAX(clientes.data_ultima_compra) as uc')
            ->selectRaw('MIN(clientes.id) as ancora_id')
            // Um cliente de rede costuma ter filiais em mais de um estado: a UF da âncora
            // sozinha diria "SP" para quem também está no RJ (o caso "SP +1" da Carteira).
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(clientes.estado, '') ORDER BY clientes.estado SEPARATOR ',') as ufs")
            ->groupBy('clientes.cod_cliente');

        $total = DB::query()->fromSub($grupos, 'g')->count();

        /*
         * Com a busca da página (`?busca=`), quem casou vem primeiro e volta marcado: é por
         * ele que a rede apareceu ("encontrado: OBA HORTIFRUTI"). Mesma regra de
         * `BuscaNasRedes` (`TermoDeBusca`) — nome fantasia, razão social ou CNPJ.
         */
        $busca = trim((string) $request->string('busca'));
        $casa = '0';
        $bindings = [];
        if ($busca !== '') {
            [$sql, $bindings] = TermoDeBusca::sql(['clientes.nome_fantasia', 'clientes.razao_social'], $busca, 'clientes.cnpj_digitos');
            $casa = "MAX({$sql})";
        }

        $pagina = (clone $grupos)
            ->selectRaw("{$casa} as casa_busca", $bindings)
            // ⚠️ Só com busca: `ORDER BY 0` o MySQL lê como "a coluna nº 0" e a rede não abre.
            ->when($busca !== '', fn ($q) => $q->orderByRaw("{$casa} DESC", $bindings))
            ->orderByRaw('MAX(clientes.data_ultima_compra) IS NULL, MAX(clientes.data_ultima_compra) DESC')
            ->limit(self::LIMITE_CLIENTES_EXPANDIDOS)
            ->get();

        $ancoras = Cliente::query()
            ->whereIntegerInRaw('id', $pagina->pluck('ancora_id')->all())
            ->get(['id', 'cod_cliente', 'razao_social', 'nome_fantasia', 'estado', 'cod_vendedor', 'cod_grupo'])
            ->keyBy('id');

        $nomes = $nomeVendedor->porCodigo($ancoras->pluck('cod_vendedor'));
        $grupoNomes = GrupoCliente::query()->whereIn('codigo', $ancoras->pluck('cod_grupo')->filter())->pluck('nome', 'codigo');
        $hoje = Carbon::now();

        return response()->json([
            'total' => $total,
            'limite' => self::LIMITE_CLIENTES_EXPANDIDOS,
            'clientes' => $pagina->map(function ($g) use ($ancoras, $nomes, $grupoNomes, $status, $hoje) {
                $a = $ancoras->get($g->ancora_id);
                $uc = $g->uc ? Carbon::parse($g->uc) : null;

                return [
                    'id' => $g->ancora_id,
                    'codCliente' => $g->cod_cliente,
                    /*
                     * A linha é o CLIENTE (todas as lojas do código), então o nome é o da
                     * empresa — a razão social, como na Carteira agrupada. O nome fantasia
                     * da âncora é de UMA loja ("REDE OBA - VILA NOVA"), e ao lado do status
                     * e da última compra do cliente inteiro parecia que aquela loja estava
                     * ativa (Tony, 13/10/2026).
                     */
                    'nome' => $a?->razao_social ?: $a?->nome_fantasia,
                    'razaoSocial' => $a?->razao_social,
                    'estado' => $a?->estado,
                    // Todas as UFs das filiais, a da âncora primeiro (é a que a célula mostra).
                    'ufs' => collect(explode(',', (string) $g->ufs))->filter()
                        ->sortBy(fn ($uf) => $uf === $a?->estado ? 0 : 1)->values()->all(),
                    'grupo' => $a?->cod_grupo ? ($grupoNomes[$a->cod_grupo] ?? $a->cod_grupo) : null,
                    'vendedor' => $a?->cod_vendedor ? ($nomes[$a->cod_vendedor] ?? $a->cod_vendedor) : null,
                    'lojas' => (int) $g->lojas,
                    'ultimaCompra' => $uc?->toDateString(),
                    'status' => $status->statusPara($uc, $hoje),
                    'casaBusca' => (bool) $g->casa_busca,
                ];
            })->all(),
        ]);
    }

    /**
     * Todas as versões da observação da conta, da mais recente para a mais antiga. A
     * primeira é a vigente; `texto` nulo marca o momento em que alguém apagou o texto.
     */
    public function observacoes(ContaEstrategica $conta): JsonResponse
    {
        return response()->json(
            $conta->observacoes()
                ->with('user:id,name,display_name')
                ->orderByDesc('id')
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'texto' => $o->texto,
                    'autor' => $o->nomeAutor(),
                    'criadoEm' => $o->created_at->format('d/m/Y H:i'),
                ])
                ->all()
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        $conta = DB::transaction(function () use ($dados) {
            $conta = ContaEstrategica::create([
                ...collect($dados)->except('vinculos')->all(),
                // Conta nova entra no fim do segmento.
                'ordem' => (int) ContaEstrategica::where('segmento_id', $dados['segmento_id'])->max('ordem') + 1,
            ]);

            $this->clientesDaConta->sincronizarVinculos($conta, $dados['vinculos'] ?? []);

            return $conta;
        });

        return back()->with('success', "Conta \"{$conta->nome}\" cadastrada.");
    }

    public function update(Request $request, ContaEstrategica $conta): RedirectResponse
    {
        $dados = $this->validar($request, $conta);

        DB::transaction(function () use ($conta, $dados) {
            $conta->update(collect($dados)->except('vinculos')->all());
            $this->clientesDaConta->sincronizarVinculos($conta, $dados['vinculos'] ?? []);
        });

        return back()->with('success', "Conta \"{$conta->nome}\" atualizada.");
    }

    /**
     * "Gerar lead": a conta-alvo sem loja nossa vira lead no funil, já na carteira do
     * responsável escolhido. Regras em `LeadDaConta`.
     */
    public function gerarLead(Request $request, ContaEstrategica $conta, LeadDaConta $leadDaConta): RedirectResponse
    {
        $dados = $request->validate([
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'recado' => ['nullable', 'string', 'max:2000'],
            'cnpj' => ['nullable', 'string', 'max:20'],
        ], [
            'responsavel_id.required' => 'Escolha quem vai trabalhar este lead.',
        ]);

        $responsavel = User::query()->with('vendedorPerfil')->findOrFail($dados['responsavel_id']);
        $lead = $leadDaConta->gerar($conta, $responsavel, $request->user(), $dados['recado'] ?? null, $dados['cnpj'] ?? null);

        $nome = $responsavel->display_name ?: $responsavel->name;

        return back()->with('success', $lead->wasRecentlyCreated
            ? "Lead \"{$lead->nome}\" aberto com {$nome}."
            : "O lead \"{$lead->nome}\" já existia sem dono — agora é de {$nome}.");
    }

    public function destroy(ContaEstrategica $conta): RedirectResponse
    {
        $nome = $conta->nome;
        $conta->delete();

        return back()->with('success', "Conta \"{$nome}\" excluída.");
    }

    /**
     * As abas da tela: as de `AbasDaPlanilha` (têm redes) na ordem dela, depois os demais
     * segmentos do maior para o menor (clientes + leads). O tamanho só ordena: não vai para a
     * tela, onde seria um terceiro número para a mesma coisa.
     *
     * @return list<array{id: int, codigo: string, nome: string}>
     */
    private function abas(UniversoDoSegmento $universo): array
    {
        $totais = $universo->totaisPorSegmento();
        $daPlanilha = AbasDaPlanilha::codigos();

        return Segmento::query()->get(['id', 'codigo', 'nome'])
            ->sortBy(fn (Segmento $s) => in_array((string) $s->codigo, $daPlanilha, true)
                ? sprintf('0-%02d', array_search((string) $s->codigo, $daPlanilha, true))
                : sprintf('1-%09d', 999999999 - ($totais[(string) $s->codigo] ?? 0)))
            ->map(fn (Segmento $s) => ['id' => $s->id, 'codigo' => (string) $s->codigo, 'nome' => $s->nome])
            ->values()
            ->all();
    }

    /**
     * @return array{segmento?: string, status?: string, uf?: string, busca?: string}
     */
    private function filtros(Request $request): array
    {
        $status = (string) $request->string('status');
        $segmento = (string) $request->string('segmento');

        return [
            // Segmento que não existe vira o Resumo, em vez de uma aba vazia sem nome.
            'segmento' => UniversoDoSegmento::segmentoValido($segmento) ? $segmento : '',
            // Whitelist: valor desconhecido vira "sem filtro" em vez de tela vazia.
            'status' => array_key_exists($status, MaioresPorSegmentoResolver::FILTROS_STATUS) ? $status : '',
            'uf' => mb_strtoupper(substr((string) $request->string('uf'), 0, 2)),
            'busca' => trim((string) $request->string('busca')),
        ];
    }

    private function validar(Request $request, ?ContaEstrategica $conta = null): array
    {
        $request->merge([
            'uf' => $request->filled('uf') ? mb_strtoupper(trim((string) $request->input('uf'))) : null,
            'nome' => trim((string) $request->input('nome')),
            // "OBA,  Oba Hortifruti ," → "OBA, Oba Hortifruti": um formato só, e vazio vira nulo.
            'apelidos' => implode(', ', BuscaNasRedes::apelidos($request->input('apelidos'))) ?: null,
        ]);

        $validator = validator($request->all(), [
            'segmento_id' => ['required', 'integer', Rule::exists('segmentos', 'id')],
            'nome' => [
                'required', 'string', 'max:150',
                Rule::unique('contas_estrategicas', 'nome')
                    ->where('segmento_id', $request->input('segmento_id'))
                    ->ignore($conta?->id),
            ],
            'uf' => ['nullable', 'string', 'size:2', 'alpha'],
            'filiais_mercado' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'site' => ['nullable', 'string', 'max:150'],
            'apelidos' => ['nullable', 'string', 'max:500'],
            'observacao' => ['nullable', 'string', 'max:5000'],
            'vinculos' => ['array', 'max:200'],
            'vinculos.*.tipo' => ['required', Rule::in(ContaEstrategicaVinculo::TIPOS)],
            'vinculos.*.codigo' => ['required', 'string', 'max:20'],
            'vinculos.*.origem' => ['nullable', Rule::in([ContaEstrategicaVinculo::ORIGEM_SUGESTAO, ContaEstrategicaVinculo::ORIGEM_MANUAL])],
        ], [
            'nome.unique' => 'Já existe uma conta com esse nome neste segmento.',
        ]);

        /*
         * Vínculo para código que não existe não quebraria nada — só contaria zero em
         * silêncio, e a conta apareceria como "Lead" sem ninguém entender por quê.
         */
        $validator->after(function (Validator $v) use ($request) {
            $vinculos = collect($request->input('vinculos', []));

            foreach ($vinculos as $i => $vinculo) {
                $tipo = $vinculo['tipo'] ?? null;
                $codigo = trim((string) ($vinculo['codigo'] ?? ''));

                if ($tipo === ContaEstrategicaVinculo::TIPO_GRUPO && in_array($codigo, ContaEstrategicaVinculo::GRUPOS_PROIBIDOS, true)) {
                    $v->errors()->add("vinculos.{$i}.codigo", "O grupo {$codigo} (CLIENTES DIVERSOS) não pode ser vinculado: ele junta clientes sem grupo real.");
                }
            }

            $grupos = $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_GRUPO)->pluck('codigo')->all();
            $codigos = $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)->pluck('codigo')->all();

            $gruposExistentes = GrupoCliente::whereIn('codigo', $grupos)->pluck('codigo')->all();
            $codigosExistentes = Cliente::whereIn('cod_cliente', $codigos)->distinct()->pluck('cod_cliente')->all();

            foreach (array_diff($grupos, $gruposExistentes) as $codigo) {
                $v->errors()->add('vinculos', "Grupo {$codigo} não existe no TOTVS.");
            }

            foreach (array_diff($codigos, $codigosExistentes) as $codigo) {
                $v->errors()->add('vinculos', "Cliente {$codigo} não existe na carteira.");
            }
        });

        return $validator->validate();
    }
}
