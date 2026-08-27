<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\ClienteParaCadastro;
use App\Models\Lead;
use App\Models\SolicitacaoBobina;
use App\Models\SolicitacaoEtiqueta;
use App\Models\User;
use App\Services\Cadastros\SolicitacaoTituloResolver;
use App\Services\Solicitacoes\BobinaPdfPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CadastroController extends Controller
{
    use ExportaPlanilha;

    private const ESTADOS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    private const SEGMENTOS = [
        'Automotivo', 'Industrial', 'Comercial', 'Logística', 'Residencial', 'Agrícola', 'Outros',
    ];

    private const EMAILS = [
        'pcp' => 'pcp.sp@autopel.com',
        'cadastro' => 'cadastro@autopel.com',
        'cadastroCliente' => 'cadastro.cliente@autopel.com',
    ];

    public function __construct(
        private readonly SolicitacaoTituloResolver $tituloResolver,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $isGestor = in_array($role, ['admin', 'diretor', 'supervisor'], true);

        $aba = in_array($request->string('aba')->value(), ['clientes', 'bobinas', 'etiquetas'], true)
            ? $request->string('aba')->value()
            : 'clientes';

        $subAbaClientes = $request->string('sub')->value() === 'lead' ? 'lead' : 'cliente';
        $busca = trim((string) $request->string('busca'));
        $status = trim((string) $request->string('status'));

        $codVendedor = $user->vendedorPerfil?->cod_vendedor;
        $solicitanteNome = $user->display_name ?: $user->name;

        $etiquetasOpcoes = $this->etiquetasOpcoes();

        return Inertia::render('Cadastros/Index', [
            'role' => $role,
            'aba' => $aba,
            'subAbaClientes' => $subAbaClientes,
            'bobinas' => $this->bobinasQuery($request, $user, $isGestor)->paginate(20)->withQueryString()->through(fn (SolicitacaoBobina $s) => $this->mapBobina($s)),
            'etiquetas' => $this->etiquetasQuery($request, $user, $isGestor)->paginate(20)->withQueryString()->through(fn (SolicitacaoEtiqueta $s) => $this->mapEtiqueta($s)),
            'clientesFila' => $this->clientesQuery($request, $user, $isGestor)->paginate(20)->withQueryString()->through(fn (ClienteParaCadastro $c) => $this->mapCliente($c)),
            'leads' => $this->leadsQuery($request, $user, $isGestor)->paginate(20)->withQueryString()->through(fn (Lead $l) => $this->mapLead($l)),
            'filtros' => [
                'busca' => $busca,
                'status' => $status,
            ],
            'opcoes' => [
                'etiquetas' => $etiquetasOpcoes,
                'estados' => self::ESTADOS,
                'segmentos' => self::SEGMENTOS,
            ],
            'meta' => [
                'solicitanteNome' => $solicitanteNome,
                'codVendedor' => $codVendedor,
                'emails' => self::EMAILS,
            ],
            'flashMailto' => $request->session()->get('flashMailto'),
        ]);
    }

    /**
     * Ficha da solicitação em PDF — o legado tinha isso
     * (`includes/pdf/solicitacao_bobina_pdf.php`) e o v2 só mandava `mailto:`.
     */
    public function pdfBobina(Request $request, SolicitacaoBobina $bobina): HttpResponse
    {
        $user = $request->user();
        $isGestor = in_array($user->getRoleNames()->first(), ['admin', 'diretor', 'supervisor'], true);

        // Mesmo escopo da listagem: quem não é gestor só vê a própria solicitação.
        abort_unless($isGestor || $bobina->user_id === $user->id, 403);

        $bobina->load('enviadoPor');

        $pdf = Pdf::loadView('solicitacoes.bobina-pdf', app(BobinaPdfPresenter::class)->montar($bobina));
        $nomeArquivo = "solicitacao-bobina-{$bobina->id}.pdf";

        return $request->boolean('download')
            ? $pdf->download($nomeArquivo)
            : $pdf->stream($nomeArquivo);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        $this->prepararExport('cadastros');

        $user = $request->user();
        $isGestor = in_array($user->getRoleNames()->first(), ['admin', 'diretor', 'supervisor'], true);
        $recurso = (string) $request->string('recurso');
        abort_unless(in_array($recurso, ['bobina', 'etiqueta', 'cliente', 'lead'], true), 404);

        $query = match ($recurso) {
            'bobina' => $this->bobinasQuery($request, $user, $isGestor),
            'etiqueta' => $this->etiquetasQuery($request, $user, $isGestor),
            'cliente' => $this->clientesQuery($request, $user, $isGestor),
            'lead' => $this->leadsQuery($request, $user, $isGestor),
        };

        return Excel::download(
            new \App\Exports\CadastroExport($query, $recurso),
            "cadastros-{$recurso}-".now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Escopo (user_id se não gestor) + busca/status. Usado por index() e exportar(). */
    protected function bobinasQuery(Request $request, User $user, bool $isGestor): Builder
    {
        $query = SolicitacaoBobina::query()->latest();
        if (! $isGestor) {
            $query->where('user_id', $user->id);
        }

        $busca = trim((string) $request->string('busca'));
        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('nomenclatura', 'like', "%{$busca}%")
                    ->orWhere('titulo_padronizado', 'like', "%{$busca}%")
                    ->orWhere('papel', 'like', "%{$busca}%")
                    ->orWhere('observacoes', 'like', "%{$busca}%");
            });
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    /** Escopo (user_id se não gestor) + busca/status. Usado por index() e exportar(). */
    protected function etiquetasQuery(Request $request, User $user, bool $isGestor): Builder
    {
        $query = SolicitacaoEtiqueta::query()->latest();
        if (! $isGestor) {
            $query->where('user_id', $user->id);
        }

        $busca = trim((string) $request->string('busca'));
        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('nomenclatura', 'like', "%{$busca}%")
                    ->orWhere('titulo_padronizado', 'like', "%{$busca}%")
                    ->orWhere('medidas', 'like', "%{$busca}%")
                    ->orWhere('tipo_adesivo', 'like', "%{$busca}%")
                    ->orWhere('observacoes', 'like', "%{$busca}%");
            });
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    /** Escopo (user_id se não gestor) + busca/status. Usado por index() e exportar(). */
    protected function clientesQuery(Request $request, User $user, bool $isGestor): Builder
    {
        $query = ClienteParaCadastro::query()->latest();
        if (! $isGestor) {
            $query->where('user_id', $user->id);
        }

        $busca = trim((string) $request->string('busca'));
        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('cnpj_faturamento', 'like', "%{$busca}%")
                    ->orWhere('razao_social', 'like', "%{$busca}%")
                    ->orWhere('nome_fantasia', 'like', "%{$busca}%");
            });
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    /** Escopo (user_id se não gestor) + busca/status. Usado por index() e exportar(). */
    protected function leadsQuery(Request $request, User $user, bool $isGestor): Builder
    {
        $query = Lead::query()->where('origem', 'manual')->visivel()->latest();
        if (! $isGestor) {
            $query->where('user_id', $user->id);
        }

        $busca = trim((string) $request->string('busca'));
        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('razao_social', 'like', "%{$busca}%")
                    ->orWhere('cnpj', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', "%{$busca}%");
            });
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    public function storeBobina(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'nomenclatura' => ['required', 'string', 'max:255'],
            'personalizacao' => ['nullable', Rule::in(['personalizada', 'sem_impressao'])],
            'unidade_venda' => ['nullable', Rule::in(['caixa', 'unidade'])],
            'quantidade_caixa' => ['nullable', 'integer', 'min:0'],
            'papel' => ['nullable', Rule::in(['termicco', 'termoscript', 'kpr', 'termobank', 'termoticket'])],
            'gramatura' => ['nullable', Rule::in(['44', '48', '55', '72', '105', '167'])],
            'largura' => ['nullable', Rule::in(['50', '55', '57', '58', '60', '69', '76', '80', '82', '88', '100', '104', '105', '110', '111', '112', '210', '400'])],
            'metragem' => ['nullable', 'numeric', 'min:0'],
            'diametro_tubete' => ['nullable', 'numeric', 'min:0'],
            'estoque_seguranca_sn' => ['nullable', Rule::in(['sim', 'nao'])],
            'estoque_seguranca' => ['nullable', 'integer', 'min:0', 'required_if:estoque_seguranca_sn,sim'],
            'impressao' => ['nullable', Rule::in(['verso', 'frente_lado_termico', 'frente_verso'])],
            'rebobinamento' => ['nullable', Rule::in(['lado_termico_fora', 'lado_termico_dentro'])],
            'observacoes' => ['nullable', 'string'],
            'nf_pedido_tipo' => ['nullable', Rule::in(['venda', 'servico'])],
        ]);

        $titulo = $this->tituloResolver->bobina(
            $data['nomenclatura'],
            $data['papel'] ?? null,
            $data['largura'] ?? null,
            $data['metragem'] ?? null,
            $data['gramatura'] ?? null,
        );

        $solicitacao = SolicitacaoBobina::query()->create([
            ...$data,
            'user_id' => $user->id,
            'solicitante_nome' => $user->display_name ?: $user->name,
            'cod_vendedor' => $user->vendedorPerfil?->cod_vendedor,
            'titulo_padronizado' => $titulo,
            'status' => 'pendente',
        ]);

        return back()->with('flashMailto', $this->mailtoBobina($solicitacao));
    }

    public function enviarBobina(Request $request, SolicitacaoBobina $bobina): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $bobina->user_id);

        $bobina->update([
            'status' => 'enviado',
            'enviado_por' => $request->user()->id,
            'enviado_em' => now(),
        ]);

        return back()->with('flashMailto', $this->mailtoBobina($bobina->fresh()));
    }

    public function destroyBobina(Request $request, SolicitacaoBobina $bobina): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $bobina->user_id, permitirGestor: true);
        $bobina->delete();

        return back();
    }

    public function storeEtiqueta(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'nomenclatura' => ['required', 'string', 'max:255'],
            'personalizacao' => ['nullable', Rule::in(['impresso', 'sem_impressao'])],
            'unidade_venda' => ['nullable', Rule::in(['caixa', 'unidade', 'pacote_manual'])],
            'quantidade_caixa' => ['nullable', 'integer', 'min:0'],
            'metragem' => ['nullable', 'numeric', 'min:0'],
            'medidas' => ['nullable', 'string', 'max:150'],
            'diametro_tubete' => ['nullable', 'string', 'max:80'],
            'aplicacao' => ['nullable', 'string', 'max:150'],
            'tipo_adesivo' => ['nullable', 'string', 'max:150'],
            'estoque_seguranca_sn' => ['nullable', Rule::in(['sim', 'nao'])],
            'estoque_seguranca' => ['nullable', 'integer', 'min:0', 'required_if:estoque_seguranca_sn,sim'],
            'saida_rolo' => ['nullable', Rule::in(['f1', 'f2', 'f3', 'f4'])],
            'observacoes' => ['nullable', 'string'],
        ]);

        $data['saida_rolo'] = $data['saida_rolo'] ?? 'f1';

        $titulo = $this->tituloResolver->etiqueta(
            $data['nomenclatura'],
            $data['medidas'] ?? null,
            $data['tipo_adesivo'] ?? null,
            $data['metragem'] ?? null,
        );

        $solicitacao = SolicitacaoEtiqueta::query()->create([
            ...$data,
            'user_id' => $user->id,
            'solicitante_nome' => $user->display_name ?: $user->name,
            'cod_vendedor' => $user->vendedorPerfil?->cod_vendedor,
            'titulo_padronizado' => $titulo,
            'status' => 'pendente',
        ]);

        return back()->with('flashMailto', $this->mailtoEtiqueta($solicitacao));
    }

    public function enviarEtiqueta(Request $request, SolicitacaoEtiqueta $etiqueta): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $etiqueta->user_id);

        $etiqueta->update([
            'status' => 'enviado',
            'enviado_por' => $request->user()->id,
            'enviado_em' => now(),
        ]);

        return back()->with('flashMailto', $this->mailtoEtiqueta($etiqueta->fresh()));
    }

    public function destroyEtiqueta(Request $request, SolicitacaoEtiqueta $etiqueta): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $etiqueta->user_id, permitirGestor: true);
        $etiqueta->delete();

        return back();
    }

    public function storeCliente(Request $request): RedirectResponse
    {
        $user = $request->user();
        $modoEntrega = $request->string('cadastro_raiz_opcao')->value() === 'nova_entrega';

        $data = $request->validate([
            'cnpj_faturamento' => ['required', 'string', 'max:18'],
            'cadastro_raiz_opcao' => ['nullable', Rule::in(['filial', 'nova_entrega'])],
            'inscricao_estadual' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:30'],
            'razao_social' => ['nullable', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'condicao_pagamento' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:120'],
            'grupo_vendas' => ['nullable', Rule::in(['sim', 'nao'])],
            'grupo_vendas_codigo' => ['nullable', 'required_if:grupo_vendas,sim', 'string', 'max:80'],
            'tabela_preco' => ['nullable', Rule::in(['sim', 'nao'])],
            'tabela_preco_codigo' => ['nullable', 'required_if:tabela_preco,sim', 'string', 'max:120'],
            'segmento_atuacao' => [$modoEntrega ? 'nullable' : 'required', Rule::in(self::SEGMENTOS)],
            'endereco' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'cep' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:10'],
            'bairro' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:100'],
            'municipio' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:100'],
            'estado' => [$modoEntrega ? 'nullable' : 'required', Rule::in(self::ESTADOS)],
            'entrega_diferente' => ['nullable', 'boolean'],
            'entrega_endereco' => ['nullable', 'required_if:entrega_diferente,true', 'required_if:cadastro_raiz_opcao,nova_entrega', 'string', 'max:255'],
            'entrega_complemento' => ['nullable', 'string', 'max:255'],
            'entrega_cep' => ['nullable', 'required_if:entrega_diferente,true', 'required_if:cadastro_raiz_opcao,nova_entrega', 'string', 'max:12'],
            'entrega_bairro' => ['nullable', 'required_if:entrega_diferente,true', 'required_if:cadastro_raiz_opcao,nova_entrega', 'string', 'max:100'],
            'entrega_municipio' => ['nullable', 'required_if:entrega_diferente,true', 'required_if:cadastro_raiz_opcao,nova_entrega', 'string', 'max:100'],
            'entrega_estado' => ['nullable', 'required_if:entrega_diferente,true', 'required_if:cadastro_raiz_opcao,nova_entrega', Rule::in(self::ESTADOS)],
            'telefone' => [$modoEntrega ? 'nullable' : 'required', 'string', 'max:20'],
            'email' => [$modoEntrega ? 'nullable' : 'required', 'email', 'max:100'],
            'observacoes' => ['nullable', 'string'],
        ]);

        if ($modoEntrega) {
            $data['inscricao_estadual'] = $data['inscricao_estadual'] ?: '—';
            $data['condicao_pagamento'] = $data['condicao_pagamento'] ?: '—';
            $data['segmento_atuacao'] = $data['segmento_atuacao'] ?: 'Outros';
            $data['endereco'] = $data['endereco'] ?: '—';
            $data['cep'] = $data['cep'] ?: '—';
            $data['bairro'] = $data['bairro'] ?: '—';
            $data['municipio'] = $data['municipio'] ?: '—';
            $data['estado'] = $data['estado'] ?: 'SP';
            $data['telefone'] = $data['telefone'] ?: '—';
            $data['email'] = $data['email'] ?: 'nao-informado@sistema.autopel';
        }

        $codVendedor = $user->vendedorPerfil?->cod_vendedor;
        $nome = $user->display_name ?: $user->name;

        $cliente = ClienteParaCadastro::query()->create([
            'user_id' => $user->id,
            'cnpj_faturamento' => $data['cnpj_faturamento'],
            'vendedor_autopel' => $nome,
            'razao_social' => $data['razao_social'] ?? null,
            'nome_fantasia' => $data['nome_fantasia'] ?? null,
            'endereco' => $data['endereco'],
            'complemento' => $data['complemento'] ?? null,
            'cep' => $data['cep'],
            'bairro' => $data['bairro'],
            'municipio' => $data['municipio'],
            'estado' => $data['estado'],
            'telefone' => $data['telefone'],
            'email' => $data['email'],
            'inscricao_estadual' => $data['inscricao_estadual'],
            'segmento_atuacao' => $data['segmento_atuacao'],
            'condicao_pagamento' => $data['condicao_pagamento'] ?? '',
            'grupo_vendas' => $data['grupo_vendas'] ?? 'nao',
            'grupo_vendas_codigo' => ($data['grupo_vendas'] ?? 'nao') === 'sim' ? ($data['grupo_vendas_codigo'] ?? null) : null,
            'tabela_preco' => $data['tabela_preco'] ?? 'nao',
            'tabela_preco_codigo' => ($data['tabela_preco'] ?? 'nao') === 'sim' ? ($data['tabela_preco_codigo'] ?? null) : null,
            'observacoes' => $data['observacoes'] ?? null,
            'entrega_endereco' => $data['entrega_endereco'] ?? null,
            'entrega_complemento' => $data['entrega_complemento'] ?? null,
            'entrega_cep' => $data['entrega_cep'] ?? null,
            'entrega_bairro' => $data['entrega_bairro'] ?? null,
            'entrega_municipio' => $data['entrega_municipio'] ?? null,
            'entrega_estado' => $data['entrega_estado'] ?? null,
            'cadastro_raiz_opcao' => $data['cadastro_raiz_opcao'] ?? null,
            'cod_vendedor_carteira' => $codVendedor,
            'nome_vendedor_carteira' => $nome,
            'cod_vendedor_solicitante' => $codVendedor,
            'nome_solicitante' => $nome,
            'cadastro_proxy' => false,
            'status' => 'pendente',
        ]);

        return back()->with('flashMailto', $this->mailtoCliente($cliente));
    }

    public function destroyCliente(Request $request, ClienteParaCadastro $cliente): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $cliente->user_id, permitirGestor: true);
        $cliente->delete();

        return back();
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'email' => ['required', 'email', 'max:255'],
            'telefone' => ['required', 'string', 'max:20'],
            'endereco' => ['required', 'string', 'max:255'],
        ]);

        Lead::query()->create([
            'origem' => 'manual',
            'user_id' => $user->id,
            'nome' => $data['razao_social'],
            'razao_social' => $data['razao_social'],
            'nome_fantasia' => $data['nome_fantasia'] ?? null,
            'cnpj' => $data['cnpj'] ?? null,
            'email' => $data['email'],
            'telefone' => $data['telefone'],
            'endereco' => $data['endereco'],
            'cod_vendedor' => $user->vendedorPerfil?->cod_vendedor,
            'status' => 'ativo',
        ]);

        return back();
    }

    public function destroyLead(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($lead->origem === 'manual', 404);
        $this->autorizarSolicitacao($request->user(), $lead->user_id, permitirGestor: true);
        $lead->update(['status' => 'excluido']);

        return back();
    }

    private function autorizarSolicitacao(User $user, int $ownerId, bool $permitirGestor = false): void
    {
        $role = $user->getRoleNames()->first();
        $isGestor = in_array($role, ['admin', 'diretor', 'supervisor'], true);

        if ($user->id === $ownerId) {
            return;
        }

        if ($permitirGestor && $isGestor) {
            return;
        }

        if ($isGestor && ! $permitirGestor) {
            return;
        }

        abort(403);
    }

    /** @return array{medidas: list<string>, aplicacoes: list<string>, tipo_adesivos: list<string>, diametros_tubete: list<string>} */
    private function etiquetasOpcoes(): array
    {
        $path = resource_path('js/data/etiquetas-opcoes.json');
        $json = File::exists($path) ? json_decode(File::get($path), true) : [];

        return [
            'medidas' => $json['medidas'] ?? [],
            'aplicacoes' => $json['aplicacoes'] ?? [],
            'tipo_adesivos' => $json['tipo_adesivos'] ?? [],
            'diametros_tubete' => $json['diametros_tubete'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function mapBobina(SolicitacaoBobina $s): array
    {
        return [
            'id' => $s->id,
            'data' => $s->created_at?->format('d/m/Y H:i'),
            'tituloPadronizado' => $s->titulo_padronizado,
            'nomenclatura' => $s->nomenclatura,
            'quantidadeCaixa' => $s->quantidade_caixa,
            'estoqueSegurancaSn' => $s->estoque_seguranca_sn,
            'status' => $s->status,
            'personalizacao' => $s->personalizacao,
            'unidadeVenda' => $s->unidade_venda,
            'papel' => $s->papel,
            'gramatura' => $s->gramatura,
            'largura' => $s->largura,
            'metragem' => $s->metragem !== null ? (float) $s->metragem : null,
            'diametroTubete' => $s->diametro_tubete !== null ? (float) $s->diametro_tubete : null,
            'estoqueSeguranca' => $s->estoque_seguranca,
            'impressao' => $s->impressao,
            'rebobinamento' => $s->rebobinamento,
            'observacoes' => $s->observacoes,
            'nfPedidoTipo' => $s->nf_pedido_tipo,
            'solicitanteNome' => $s->solicitante_nome,
        ];
    }

    /** @return array<string, mixed> */
    private function mapEtiqueta(SolicitacaoEtiqueta $s): array
    {
        return [
            'id' => $s->id,
            'data' => $s->created_at?->format('d/m/Y H:i'),
            'tituloPadronizado' => $s->titulo_padronizado,
            'nomenclatura' => $s->nomenclatura,
            'medidas' => $s->medidas,
            'saidaRolo' => $s->saida_rolo,
            'status' => $s->status,
            'personalizacao' => $s->personalizacao,
            'unidadeVenda' => $s->unidade_venda,
            'quantidadeCaixa' => $s->quantidade_caixa,
            'metragem' => $s->metragem !== null ? (float) $s->metragem : null,
            'diametroTubete' => $s->diametro_tubete,
            'aplicacao' => $s->aplicacao,
            'tipoAdesivo' => $s->tipo_adesivo,
            'estoqueSeguranca' => $s->estoque_seguranca,
            'estoqueSegurancaSn' => $s->estoque_seguranca_sn,
            'observacoes' => $s->observacoes,
            'solicitanteNome' => $s->solicitante_nome,
        ];
    }

    /** @return array<string, mixed> */
    private function mapCliente(ClienteParaCadastro $c): array
    {
        return [
            'id' => $c->id,
            'data' => $c->created_at?->format('d/m/Y H:i'),
            'cnpjFaturamento' => $c->cnpj_faturamento,
            'razaoSocial' => $c->razao_social,
            'nomeFantasia' => $c->nome_fantasia,
            'segmentoAtuacao' => $c->segmento_atuacao,
            'status' => $c->status,
            'municipio' => $c->municipio,
            'estado' => $c->estado,
            'telefone' => $c->telefone,
            'email' => $c->email,
            'nomeSolicitante' => $c->nome_solicitante,
            'observacoes' => $c->observacoes,
            'cadastroRaizOpcao' => $c->cadastro_raiz_opcao,
        ];
    }

    /** @return array<string, mixed> */
    private function mapLead(Lead $l): array
    {
        return [
            'id' => $l->id,
            'data' => $l->created_at?->format('d/m/Y H:i'),
            'razaoSocial' => $l->razao_social,
            'nomeFantasia' => $l->nome_fantasia,
            'cnpj' => $l->cnpj,
            'email' => $l->email,
            'telefone' => $l->telefone,
            'endereco' => $l->endereco,
            'status' => $l->status,
        ];
    }

    /** @return array{to: string, cc: ?string, subject: string, body: string} */
    private function mailtoBobina(SolicitacaoBobina $s): array
    {
        $linhas = [
            'Há uma nova solicitação de cadastro de bobina registrada no sistema.',
            '',
            'Título TOTVS: '.$s->titulo_padronizado,
            'Nomenclatura: '.$s->nomenclatura,
            'Solicitante: '.$s->solicitante_nome,
            'Papel: '.($s->papel ?: '—'),
            'Largura: '.($s->largura ?: '—'),
            'Metragem: '.($s->metragem !== null ? (string) $s->metragem : '—'),
            'Observações: '.($s->observacoes ?: '—'),
        ];

        return [
            'to' => self::EMAILS['pcp'],
            'cc' => self::EMAILS['cadastro'],
            'subject' => '[Cadastro de Bobina] '.$s->titulo_padronizado,
            'body' => implode("\n", $linhas),
        ];
    }

    /** @return array{to: string, cc: ?string, subject: string, body: string} */
    private function mailtoEtiqueta(SolicitacaoEtiqueta $s): array
    {
        $linhas = [
            'Há uma nova solicitação de cadastro de etiqueta registrada no sistema.',
            '',
            'Título TOTVS: '.$s->titulo_padronizado,
            'Nomenclatura: '.$s->nomenclatura,
            'Solicitante: '.$s->solicitante_nome,
            'Medidas: '.($s->medidas ?: '—'),
            'Adesivo: '.($s->tipo_adesivo ?: '—'),
            'Saída: '.strtoupper($s->saida_rolo ?? 'f1'),
            'Observações: '.($s->observacoes ?: '—'),
        ];

        return [
            'to' => self::EMAILS['pcp'],
            'cc' => self::EMAILS['cadastro'],
            'subject' => '[Cadastro de Etiqueta] '.$s->titulo_padronizado,
            'body' => implode("\n", $linhas),
        ];
    }

    /** @return array{to: string, cc: ?string, subject: string, body: string} */
    private function mailtoCliente(ClienteParaCadastro $c): array
    {
        $linhas = [
            'Solicitação de cadastro de cliente no TOTVS.',
            '',
            'CNPJ: '.$c->cnpj_faturamento,
            'Razão social: '.($c->razao_social ?: '—'),
            'Fantasia: '.($c->nome_fantasia ?: '—'),
            'IE: '.$c->inscricao_estadual,
            'Segmento: '.$c->segmento_atuacao,
            'Condição pagamento: '.$c->condicao_pagamento,
            'Endereço: '.$c->endereco.', '.$c->bairro.' — '.$c->municipio.'/'.$c->estado.' CEP '.$c->cep,
            'Telefone: '.$c->telefone,
            'E-mail: '.$c->email,
            'Solicitante: '.$c->nome_solicitante,
            'Observações: '.($c->observacoes ?: '—'),
        ];

        if ($c->entrega_endereco) {
            $linhas[] = 'Entrega: '.$c->entrega_endereco.', '.$c->entrega_bairro.' — '.$c->entrega_municipio.'/'.$c->entrega_estado;
        }

        return [
            'to' => self::EMAILS['cadastroCliente'],
            'cc' => null,
            'subject' => '[Cadastro de Cliente] '.($c->razao_social ?: $c->cnpj_faturamento),
            'body' => implode("\n", $linhas),
        ];
    }
}
