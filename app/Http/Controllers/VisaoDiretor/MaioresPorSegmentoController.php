<?php

namespace App\Http\Controllers\VisaoDiretor;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Models\User;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use App\Services\VisaoDiretor\BuscaDeVinculo;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
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

    public function index(Request $request): Response
    {
        $filtros = $this->filtros($request);

        return Inertia::render('VisaoDiretor/MaioresPorSegmento', [
            /*
             * A aba (`segmento`) NÃO recorta o payload: as seis tabelas viajam juntas e
             * o front troca sem ir ao servidor — igual virar a aba no Excel. O recorte
             * continua valendo no export, que manda o filtro de verdade.
             */
            'dados' => $this->resolver->resolver([...$filtros, 'segmento' => '']),
            'filtros' => $filtros,
            // Para o select de especialista e o formulário de conta. ~200 linhas, e só
            // quem passa no gate chega aqui.
            'usuarios' => User::query()
                ->where('is_active', true)
                ->orderByRaw("COALESCE(NULLIF(display_name, ''), name)")
                ->get(['id', 'name', 'display_name'])
                ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->display_name ?: $u->name])
                ->all(),
            'segmentosDisponiveis' => Segmento::query()->orderBy('nome')->get(['id', 'codigo', 'nome']),
        ]);
    }

    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('maiores-por-segmento', $request);
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
            ->groupBy('clientes.cod_cliente');

        $total = DB::query()->fromSub($grupos, 'g')->count();

        $pagina = (clone $grupos)
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
                    'nome' => $a?->nome_fantasia ?: $a?->razao_social,
                    'razaoSocial' => $a?->razao_social,
                    'estado' => $a?->estado,
                    'grupo' => $a?->cod_grupo ? ($grupoNomes[$a->cod_grupo] ?? $a->cod_grupo) : null,
                    'vendedor' => $a?->cod_vendedor ? ($nomes[$a->cod_vendedor] ?? $a->cod_vendedor) : null,
                    'lojas' => (int) $g->lojas,
                    'ultimaCompra' => $uc?->toDateString(),
                    'status' => $status->statusPara($uc, $hoje),
                ];
            })->all(),
        ]);
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

    public function destroy(ContaEstrategica $conta): RedirectResponse
    {
        $nome = $conta->nome;
        $conta->delete();

        return back()->with('success', "Conta \"{$nome}\" excluída.");
    }

    public function especialista(Request $request, Segmento $segmento): RedirectResponse
    {
        $dados = $request->validate([
            'especialista_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $segmento->update($dados);

        return back()->with('success', "Especialista de {$segmento->nome} atualizado.");
    }

    /**
     * @return array{segmento?: string, status?: string, uf?: string, busca?: string}
     */
    private function filtros(Request $request): array
    {
        $status = (string) $request->string('status');

        return [
            'segmento' => (string) $request->string('segmento'),
            // Whitelist: valor desconhecido vira "sem filtro" em vez de tela vazia.
            'status' => in_array($status, MaioresPorSegmentoResolver::STATUS, true) ? $status : '',
            'uf' => mb_strtoupper(substr((string) $request->string('uf'), 0, 2)),
            'busca' => trim((string) $request->string('busca')),
        ];
    }

    private function validar(Request $request, ?ContaEstrategica $conta = null): array
    {
        $request->merge([
            'uf' => $request->filled('uf') ? mb_strtoupper(trim((string) $request->input('uf'))) : null,
            'nome' => trim((string) $request->input('nome')),
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
