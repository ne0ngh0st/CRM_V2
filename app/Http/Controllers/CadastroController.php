<?php

namespace App\Http\Controllers;

use App\Exports\CadastroExport;
use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Mail\CadastroSolicitacaoMail;
use App\Models\ClienteParaCadastro;
use App\Models\Lead;
use App\Models\SolicitacaoBobina;
use App\Models\SolicitacaoEtiqueta;
use App\Models\User;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Cadastros\SolicitacaoTituloResolver;
use App\Services\Solicitacoes\BobinaPdfPresenter;
use App\Services\Solicitacoes\EtiquetaPdfPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CadastroController extends Controller
{
    use ExportaPlanilha;

    /**
     * "Quem cuida do cliente?" — de quem é este CNPJ.
     *
     * Vive aqui, e não numa página própria, porque é aqui que a resposta EVITA O ERRO:
     * antes de pedir cadastro de um cliente novo, descobrir que ele já existe e já tem
     * dono. No legado era o próprio H1 da tela de cadastro.
     *
     * ⚠️ Deliberadamente SEM escopo de vendedor e deliberadamente POBRE em conteúdo —
     * ver o docblock de BuscaTitularidade, que explica por que as duas coisas andam
     * juntas.
     */
    public function titularidade(Request $request, BuscaTitularidade $busca): JsonResponse
    {
        return response()->json([
            'minimo' => BuscaTitularidade::MINIMO_CARACTERES,
            'resultados' => $busca->buscar((string) $request->string('termo')),
        ]);
    }

    private const ESTADOS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    private const SEGMENTOS = [
        'Automotivo', 'Industrial', 'Comercial', 'Logística', 'Residencial', 'Agrícola', 'Outros',
    ];

    /**
     * Destravado em 2026-08-28 depois do Tony confirmar o SMTP validado.
     * `cadastroCliente` é `cadastro.geral@autopel.com` — mudou recentemente,
     * não é mais `cadastro.cliente@autopel.com`.
     */
    private const EMAILS = [
        'pcp' => 'pcp.sp@autopel.com',
        'cadastro' => 'cadastro@autopel.com',
        'cadastroCliente' => 'cadastro.geral@autopel.com',
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
            'flashEnvio' => $request->session()->get('flashEnvio'),
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

    /** Ficha da solicitação de etiqueta em PDF — mesmo padrão da bobina. */
    public function pdfEtiqueta(Request $request, SolicitacaoEtiqueta $etiqueta): HttpResponse
    {
        $user = $request->user();
        $isGestor = in_array($user->getRoleNames()->first(), ['admin', 'diretor', 'supervisor'], true);

        abort_unless($isGestor || $etiqueta->user_id === $user->id, 403);

        $etiqueta->load('enviadoPor');

        $pdf = Pdf::loadView('solicitacoes.etiqueta-pdf', app(EtiquetaPdfPresenter::class)->montar($etiqueta));
        $nomeArquivo = "solicitacao-etiqueta-{$etiqueta->id}.pdf";

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
            new CadastroExport($query, $recurso),
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
            'tubete_obrigatorio' => ['nullable', Rule::in(['sim', 'nao'])],
            'diametro_tubete' => ['nullable', 'numeric', 'min:0', 'required_if:tubete_obrigatorio,sim'],
            'estoque_seguranca_sn' => ['nullable', Rule::in(['sim', 'nao'])],
            'estoque_seguranca' => ['nullable', 'integer', 'min:0', 'required_if:estoque_seguranca_sn,sim'],
            'observacoes' => ['nullable', 'string'],
            'nf_pedido_tipo' => ['nullable', Rule::in(['venda', 'servico'])],
        ]);

        if (($data['tubete_obrigatorio'] ?? '') !== 'sim') {
            $data['diametro_tubete'] = null;
        }

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

        $this->enviarEmailBobina($solicitacao);

        return back()->with('flashEnvio', $this->flashEnvio($this->mailtoBobina($solicitacao)));
    }

    public function enviarBobina(Request $request, SolicitacaoBobina $bobina): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $bobina->user_id);

        $bobina->update([
            'status' => 'enviado',
            'enviado_por' => $request->user()->id,
            'enviado_em' => now(),
        ]);

        $bobina = $bobina->fresh();
        $this->enviarEmailBobina($bobina);

        return back()->with('flashEnvio', $this->flashEnvio($this->mailtoBobina($bobina)));
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

        $this->enviarEmailEtiqueta($solicitacao);

        return back()->with('flashEnvio', $this->flashEnvio($this->mailtoEtiqueta($solicitacao)));
    }

    public function enviarEtiqueta(Request $request, SolicitacaoEtiqueta $etiqueta): RedirectResponse
    {
        $this->autorizarSolicitacao($request->user(), $etiqueta->user_id);

        $etiqueta->update([
            'status' => 'enviado',
            'enviado_por' => $request->user()->id,
            'enviado_em' => now(),
        ]);

        $etiqueta = $etiqueta->fresh();
        $this->enviarEmailEtiqueta($etiqueta);

        return back()->with('flashEnvio', $this->flashEnvio($this->mailtoEtiqueta($etiqueta)));
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

        $this->enviarEmail($this->mailtoCliente($cliente));

        return back()->with('flashEnvio', $this->flashEnvio($this->mailtoCliente($cliente)));
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
            'tubeteObrigatorio' => $s->tubete_obrigatorio,
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

    /**
     * Envia (enfileirado) o e-mail de notificação pro setor responsável.
     * O solicitante SEMPRE vai em cópia (pedido do Tony, 2026-08-28) — além
     * do `cc` fixo do setor (quando houver), nunca no lugar dele.
     */
    private function enviarEmail(array $dados, ?string $anexoConteudo = null, ?string $anexoNome = null): void
    {
        $ccs = array_values(array_unique(array_filter([
            $dados['cc'] ?? null,
            $dados['solicitanteEmail'] ?? null,
        ])));

        $assunto = $dados['subject'];
        $destino = $dados['to'];

        /*
         * Modo teste: manda tudo para um endereço só, sem cc.
         *
         * ⚠️ `config()`, nunca `env()`: em produção o config é cacheado e o .env sequer
         * é lido, então `env()` devolveria null e o redirecionamento sumiria justamente
         * onde ele protege (armadilha 9.2 do docs/deploy-aws.md).
         *
         * O prefixo no assunto carrega o destino real porque o objetivo do teste é
         * conferir o ROTEAMENTO; sem ele você vê o conteúdo e continua sem saber se
         * teria ido pro PCP ou pro Cadastro.
         */
        if ($redirecionar = config('cadastros.redirecionar_emails_para')) {
            $reais = implode(', ', array_values(array_unique(array_filter(
                array_merge([$destino], $ccs)
            ))));

            $assunto = "[TESTE → {$reais}] {$assunto}";
            $destino = $redirecionar;
            $ccs = [];
        }

        $mail = Mail::to($destino);
        if ($ccs !== []) {
            $mail = $mail->cc($ccs);
        }

        $mail->queue(new CadastroSolicitacaoMail($assunto, $dados['body'], $anexoConteudo, $anexoNome));
    }

    /** Bobina tem ficha em PDF (mesma usada em `pdfBobina`) — vai anexada no e-mail. */
    private function enviarEmailBobina(SolicitacaoBobina $bobina): void
    {
        $bobina->loadMissing('user', 'enviadoPor');
        $pdfBinario = Pdf::loadView('solicitacoes.bobina-pdf', app(BobinaPdfPresenter::class)->montar($bobina))->output();

        $this->enviarEmail($this->mailtoBobina($bobina), $pdfBinario, "solicitacao-bobina-{$bobina->id}.pdf");
    }

    /** Etiqueta tem ficha em PDF (mesma usada em `pdfEtiqueta`) — vai anexada no e-mail. */
    private function enviarEmailEtiqueta(SolicitacaoEtiqueta $etiqueta): void
    {
        $etiqueta->loadMissing('user', 'enviadoPor');
        $pdfBinario = Pdf::loadView('solicitacoes.etiqueta-pdf', app(EtiquetaPdfPresenter::class)->montar($etiqueta))->output();

        $this->enviarEmail($this->mailtoEtiqueta($etiqueta), $pdfBinario, "solicitacao-etiqueta-{$etiqueta->id}.pdf");
    }

    /** @param array{to: string, cc: ?string, solicitanteEmail?: ?string, subject: string, body: string} $dados */
    private function flashEnvio(array $dados): array
    {
        $ccs = array_values(array_unique(array_filter([$dados['cc'] ?? null, $dados['solicitanteEmail'] ?? null])));
        $destino = $dados['to'].($ccs !== [] ? ' (cc: '.implode(', ', $ccs).')' : '');

        return [
            'mensagem' => "E-mail enviado para {$destino}.",
        ];
    }

    /**
     * Cabeçalho de seção no padrão "TÍTULO\n======\n\n" — formato do legado
     * (`pages/SISTEMA/cadastro.php::montarCorpoEmailCadastro`), unificado aqui
     * pros 3 tipos de e-mail em vez das duas convenções que coexistiam lá
     * (cabeçalho sublinhado pro cliente, "— TÍTULO —" pra bobina/etiqueta).
     */
    private function cabecalhoSecao(string $titulo): string
    {
        return $titulo."\n".str_repeat('=', mb_strlen($titulo))."\n\n";
    }

    /** @param array<string, mixed> $pares rótulo => valor (null/vazio vira "-") */
    private function linhas(array $pares): string
    {
        $corpo = '';
        foreach ($pares as $rotulo => $valor) {
            $corpo .= $rotulo.': '.($valor === null || $valor === '' ? '-' : $valor)."\n";
        }

        return $corpo;
    }

    /** @param array<string, mixed> $pares */
    private function secao(string $titulo, array $pares): string
    {
        return $this->cabecalhoSecao($titulo).$this->linhas($pares)."\n";
    }

    private function blocoTexto(string $titulo, string $texto): string
    {
        return $this->cabecalhoSecao($titulo).$texto."\n\n";
    }

    private function assinaturaEmail(): string
    {
        return "Atenciosamente,\nSistema de Gestão Comercial Autopel\n© ".now()->year.' Autopel - Todos os direitos reservados';
    }

    private function simNao(?string $valor): string
    {
        return match (mb_strtolower(trim((string) $valor))) {
            'sim' => 'Sim',
            'nao', 'não' => 'Não',
            default => '-',
        };
    }

    /**
     * @return array{to: string, cc: ?string, solicitanteEmail: ?string, subject: string, body: string}
     */
    private function mailtoBobina(SolicitacaoBobina $s): array
    {
        $s->loadMissing('user');
        $presenter = app(BobinaPdfPresenter::class)->montar($s);

        $corpo = "Olá equipe,\n\n";
        $corpo .= "Há uma nova solicitação de cadastro de bobina registrada no sistema.\n\n";
        $corpo .= "ID INTERNO: {$s->id}\n";
        $corpo .= $this->secao('RESUMO', $presenter['resumo']);
        $corpo .= $this->secao('INFORMAÇÕES COMERCIAIS', $presenter['comerciais']);
        $corpo .= $this->secao('CARACTERÍSTICAS TÉCNICAS', $presenter['tecnicas']);

        if ($s->observacoes) {
            $corpo .= $this->blocoTexto('OBSERVAÇÕES', $s->observacoes);
        }

        $corpo .= "A ficha completa em PDF está anexada a este e-mail.\n\n";
        $corpo .= $this->assinaturaEmail();

        return [
            'to' => self::EMAILS['pcp'],
            'cc' => self::EMAILS['cadastro'],
            'solicitanteEmail' => $s->user?->email,
            'subject' => '[Cadastro de Bobina] '.$s->titulo_padronizado,
            'body' => $corpo,
        ];
    }

    /**
     * @return array{to: string, cc: ?string, solicitanteEmail: ?string, subject: string, body: string}
     */
    private function mailtoEtiqueta(SolicitacaoEtiqueta $s): array
    {
        $s->loadMissing('user');

        $corpo = "Olá equipe,\n\n";
        $corpo .= "Há uma nova solicitação de cadastro de etiqueta registrada no sistema.\n\n";
        $corpo .= "ID INTERNO: {$s->id}\n";
        $corpo .= $this->secao('RESUMO', [
            'Título TOTVS' => $s->titulo_padronizado,
            'Solicitante' => $s->solicitante_nome,
            'Código vendedor' => $s->cod_vendedor,
            'Data de criação' => $s->created_at?->format('d/m/Y H:i'),
        ]);
        $corpo .= $this->secao('INFORMAÇÕES COMERCIAIS', [
            'Nomenclatura' => $s->nomenclatura,
            'Medidas (L x A)' => $s->medidas,
            'Metragem total (m)' => $s->metragem !== null ? (string) $s->metragem : null,
            'Possui estoque de segurança?' => $this->simNao($s->estoque_seguranca_sn),
            'Estoque de segurança' => $s->estoque_seguranca !== null ? (string) $s->estoque_seguranca : null,
        ]);
        $corpo .= $this->secao('CARACTERÍSTICAS TÉCNICAS', [
            'Personalização' => match ($s->personalizacao) {
                'impresso' => 'Impresso',
                'sem_impressao' => 'Sem impressão',
                default => null,
            },
            'Unidade de venda' => match ($s->unidade_venda) {
                'caixa' => 'Caixa',
                'unidade' => 'Unidade',
                'pacote_manual' => 'Pacote (manual)',
                default => null,
            },
            'Quantidade por caixa' => $s->quantidade_caixa !== null ? (string) $s->quantidade_caixa : null,
            'Diâmetro do tubete' => $s->diametro_tubete,
            'Aplicação' => $s->aplicacao,
            'Tipo de adesivo' => $s->tipo_adesivo,
        ]);

        if ($s->observacoes) {
            $corpo .= $this->blocoTexto('OBSERVAÇÕES', $s->observacoes);
        }

        $corpo .= "A ficha completa em PDF está anexada a este e-mail.\n\n";
        $corpo .= $this->assinaturaEmail();

        return [
            'to' => self::EMAILS['pcp'],
            'cc' => self::EMAILS['cadastro'],
            'solicitanteEmail' => $s->user?->email,
            'subject' => '[Cadastro de Etiqueta] '.$s->titulo_padronizado,
            'body' => $corpo,
        ];
    }

    /**
     * Corpo no formato do legado (`pages/SISTEMA/cadastro.php::montarCorpoEmailCadastro`)
     * — o Tony pediu explicitamente "igual ao legado" depois de ver o e-mail real de lá.
     * Não portamos o bloco de "raiz de CNPJ já na carteira" (matriz/filial): depende de
     * detectar duplicata por raiz de CNPJ, proibido pela Regra de ouro nº 3.
     *
     * @return array{to: string, cc: ?string, solicitanteEmail: ?string, subject: string, body: string}
     */
    private function mailtoCliente(ClienteParaCadastro $c): array
    {
        $c->loadMissing('user');

        $corpo = "Olá!\n\n";
        $corpo .= "Um novo cliente foi cadastrado no sistema e precisa ser processado.\n\n";
        $corpo .= "ID INTERNO: {$c->id}\n";
        $corpo .= $this->secao('DADOS DO CLIENTE', array_filter([
            'CNPJ de Faturamento' => $c->cnpj_faturamento,
            'Vendedor Autopel' => $c->vendedor_autopel,
            'Código do Vendedor' => $c->cod_vendedor_carteira,
        ], fn ($valor) => $valor !== null && $valor !== ''));

        $corpo .= $this->cabecalhoSecao('DADOS EMPRESARIAIS');
        $corpo .= $this->linhas([
            'Razão Social' => $c->razao_social ?: 'Não informado',
            'Nome Fantasia' => $c->nome_fantasia ?: 'Não informado',
            'Inscrição Estadual' => $c->inscricao_estadual,
            'Segmento de Atuação' => $c->segmento_atuacao,
        ]);
        $corpo .= "\n";
        $corpo .= $this->linhas([
            'Condição de Pagamento' => $c->condicao_pagamento,
            'Grupo de Vendas' => $c->grupo_vendas === 'sim'
                ? 'SIM — Código: '.($c->grupo_vendas_codigo ?: '-')
                : 'NÃO',
            'Tabela de Preço' => $c->tabela_preco === 'sim'
                ? 'SIM — '.($c->tabela_preco_codigo ?: '-')
                : 'NÃO',
        ]);
        $corpo .= "\n";

        $corpo .= $this->secao('ENDEREÇO DE COBRANÇA / FATURAMENTO', [
            'Endereço' => $c->endereco,
            'Complemento' => $c->complemento ?: 'Não informado',
            'CEP' => $c->cep,
            'Bairro' => $c->bairro,
            'Município' => $c->municipio,
            'Estado' => $c->estado,
        ]);

        if ($c->entrega_endereco) {
            $corpo .= $this->secao('ENDEREÇO DE ENTREGA', [
                'Endereço' => $c->entrega_endereco,
                'Complemento' => $c->entrega_complemento ?: 'Não informado',
                'CEP' => $c->entrega_cep,
                'Bairro' => $c->entrega_bairro,
                'Município' => $c->entrega_municipio,
                'Estado' => $c->entrega_estado,
            ]);
        }

        $corpo .= $this->secao('CONTATO', [
            'Telefone' => $c->telefone,
            'E-mail' => $c->email,
        ]);

        if ($c->observacoes) {
            $corpo .= $this->blocoTexto('OBSERVAÇÕES', $c->observacoes);
        }

        $corpo .= $this->secao('INFORMAÇÕES DO CADASTRO', [
            'Data/Hora' => $c->created_at?->format('d/m/Y H:i'),
            'Cadastrado por' => $c->nome_solicitante,
        ]);

        $corpo .= $this->assinaturaEmail();

        return [
            'to' => self::EMAILS['cadastroCliente'],
            'cc' => null,
            'solicitanteEmail' => $c->user?->email,
            'subject' => '[Cadastro de Cliente] '.($c->razao_social ?: $c->cnpj_faturamento),
            'body' => $corpo,
        ];
    }
}
