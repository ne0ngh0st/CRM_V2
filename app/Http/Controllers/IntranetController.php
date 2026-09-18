<?php

namespace App\Http\Controllers;

use App\Jobs\NotificarPublicacaoIntranetJob;
use App\Models\IntranetAnexo;
use App\Models\IntranetLeitura;
use App\Models\IntranetPublicacao;
use App\Models\User;
use App\Support\Uploads\Disco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Intranet — estágio 1 (2026-09-18).
 *
 * Todo usuário autenticado lê. Admin, diretor e supervisor publicam; o autor edita a
 * própria, e admin/diretor editam qualquer uma. A regra mora em `IntranetPublicacao`
 * (`podePublicar` / `podeSerGerenciadaPor`), e este controller só pergunta.
 */
class IntranetController extends Controller
{
    /** Quantos anexos uma publicação comporta. Sem teto, vira depósito de arquivo. */
    public const MAX_ANEXOS = 20;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $categoria = (string) $request->string('categoria');
        if (! array_key_exists($categoria, IntranetPublicacao::CATEGORIAS)) {
            $categoria = '';
        }
        $busca = trim((string) $request->string('busca'));

        $publicacoes = IntranetPublicacao::query()
            ->leftJoin('intranet_leituras as l', function ($join) use ($user) {
                $join->on('l.publicacao_id', '=', 'intranet_publicacoes.id')
                    ->where('l.user_id', '=', $user->id);
            })
            ->select('intranet_publicacoes.*', 'l.lida_em as minha_leitura', 'l.ciente_em as minha_ciencia')
            ->with('autor:id,name,display_name')
            ->withCount('anexos')
            ->when($categoria !== '', fn ($q) => $q->where('categoria', $categoria))
            ->when($busca !== '', function ($q) use ($busca) {
                // Tabela de centenas de linhas: o `LIKE '%…%'` sem índice custa nada aqui.
                $q->where(function ($q) use ($busca) {
                    $q->where('titulo', 'like', "%{$busca}%")
                        ->orWhere('corpo', 'like', "%{$busca}%");
                });
            })
            ->ordemDaLista()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (IntranetPublicacao $p) => [
                'id' => $p->id,
                'categoria' => $p->categoria,
                'categoriaRotulo' => IntranetPublicacao::rotuloDaCategoria($p->categoria),
                'titulo' => $p->titulo,
                'resumo' => $p->resumo(),
                'autor' => $this->nomeDe($p->autor),
                'publicadaEm' => $p->publicada_em->toIso8601String(),
                'editadaEm' => $p->editada_em?->toIso8601String(),
                'importante' => $p->importante,
                'fixada' => $p->fixada,
                'exigeCiencia' => $p->exige_ciencia,
                'lida' => $p->minha_leitura !== null,
                'cienciaPendente' => $p->exige_ciencia && $p->minha_ciencia === null,
                'totalAnexos' => $p->anexos_count,
            ]);

        return Inertia::render('Intranet/Index', [
            'publicacoes' => $publicacoes,
            'filtros' => ['categoria' => $categoria, 'busca' => $busca],
            'categorias' => IntranetPublicacao::categoriasParaOFront(),
            'podePublicar' => IntranetPublicacao::podePublicar($user),
        ]);
    }

    public function show(Request $request, IntranetPublicacao $publicacao): Response
    {
        $user = $request->user();

        /*
         * ⚠️ Durante uma simulação ("ver como X"), quem está lendo é o ADMIN, não o alvo.
         * Registrar a leitura marcaria como lida, para o vendedor, uma regra que ele nunca
         * viu — e zeraria o contador dele. Mesmo princípio do RegistrarAtividade: presença
         * e leitura seguem a pessoa, não o guard.
         */
        if (! $this->simulando($request)) {
            $publicacao->registrarLeitura($user);
        }

        $publicacao->load(['autor:id,name,display_name', 'anexos']);

        $minha = IntranetLeitura::query()
            ->where('publicacao_id', $publicacao->id)
            ->where('user_id', $user->id)
            ->first();

        $gerencia = $publicacao->podeSerGerenciadaPor($user);

        [$imagens, $arquivos] = $publicacao->anexos->partition(fn (IntranetAnexo $a) => $a->ehImagem());

        return Inertia::render('Intranet/Show', [
            'publicacao' => [
                ...$this->dadosBasicos($publicacao),
                'corpoHtml' => IntranetPublicacao::renderizar($publicacao->corpo),
                'imagens' => $imagens->map(fn (IntranetAnexo $a) => $this->dadosDoAnexo($a) + [
                    'url' => Disco::urlUpload($a->caminho),
                ])->values(),
                'arquivos' => $arquivos->map(fn (IntranetAnexo $a) => $this->dadosDoAnexo($a))->values(),
                'cienteEm' => $minha?->ciente_em?->toIso8601String(),
            ],
            'pode' => [
                'gerenciar' => $gerencia,
                // Simulando, o botão some: a ciência seria registrada em nome do alvo.
                'darCiencia' => $publicacao->exige_ciencia && ! $this->simulando($request),
            ],
            'ciencia' => $gerencia && $publicacao->exige_ciencia ? $this->painelDeCiencia($publicacao) : null,
            'leituras' => $gerencia ? $publicacao->leituras()->count() : null,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless(IntranetPublicacao::podePublicar($request->user()), 403);

        return Inertia::render('Intranet/Form', [
            'publicacao' => null,
            'categorias' => IntranetPublicacao::categoriasParaOFront(),
            'limites' => $this->limitesDeAnexo(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(IntranetPublicacao::podePublicar($user), 403);

        $publicacao = IntranetPublicacao::create([
            ...$this->validar($request),
            'user_id' => $user->id,
            'publicada_em' => now(),
        ]);

        NotificarPublicacaoIntranetJob::dispatch($publicacao->id);

        // A tela usa o id para subir, na sequência, os anexos escolhidos antes de a
        // publicação existir — mesmo desenho do Catálogo de Facas.
        return back()->with('recursoCriadoId', $publicacao->id);
    }

    public function edit(Request $request, IntranetPublicacao $publicacao): Response
    {
        abort_unless($publicacao->podeSerGerenciadaPor($request->user()), 403);

        $publicacao->load('anexos');

        return Inertia::render('Intranet/Form', [
            'publicacao' => [
                ...$this->dadosBasicos($publicacao),
                'corpo' => $publicacao->corpo,
                'anexos' => $publicacao->anexos->map(fn (IntranetAnexo $a) => $this->dadosDoAnexo($a))->values(),
            ],
            'categorias' => IntranetPublicacao::categoriasParaOFront(),
            'limites' => $this->limitesDeAnexo(),
        ]);
    }

    public function update(Request $request, IntranetPublicacao $publicacao): RedirectResponse
    {
        abort_unless($publicacao->podeSerGerenciadaPor($request->user()), 403);

        $dados = $this->validar($request);
        $pedirDeNovo = $request->boolean('pedir_ciencia_de_novo') && $dados['exige_ciencia'];

        DB::transaction(function () use ($publicacao, $dados, $pedirDeNovo) {
            $publicacao->fill($dados);

            if ($publicacao->isDirty(['titulo', 'corpo'])) {
                $publicacao->editada_em = now();
            }

            /*
             * ⚠️ "Mudança relevante": zera a ciência de TODOS e sobe a revisão. Sem isso, uma
             * regra alterada continuaria marcada como "ciente" por quem leu a versão antiga —
             * e o painel do autor diria que a equipe inteira concordou com um texto que ela
             * nunca viu. A leitura (`lida_em`) fica: ler, a pessoa leu.
             */
            if ($pedirDeNovo) {
                $publicacao->revisao_ciencia++;
                $publicacao->leituras()->update(['ciente_em' => null]);
            }

            $publicacao->save();
        });

        if ($pedirDeNovo) {
            NotificarPublicacaoIntranetJob::dispatch($publicacao->id, NotificarPublicacaoIntranetJob::MOTIVO_CIENCIA);
        }

        return redirect()->route('intranet.show', $publicacao);
    }

    /**
     * Soft delete. ⚠️ Os arquivos ficam no disco e as leituras ficam no banco: apagar uma
     * regra não pode levar junto o registro de quem deu ciência nela.
     */
    public function destroy(Request $request, IntranetPublicacao $publicacao): RedirectResponse
    {
        abort_unless($publicacao->podeSerGerenciadaPor($request->user()), 403);

        $publicacao->delete();

        return redirect()->route('intranet.index');
    }

    public function ciente(Request $request, IntranetPublicacao $publicacao): RedirectResponse
    {
        abort_unless($publicacao->exige_ciencia, 422, 'Esta publicação não pede ciência.');
        abort_if($this->simulando($request), 403, 'Em simulação, a ciência seria registrada em nome de outra pessoa.');

        $agora = now();

        DB::table('intranet_leituras')->upsert(
            [[
                'publicacao_id' => $publicacao->id,
                'user_id' => $request->user()->id,
                'lida_em' => $agora,
                'ciente_em' => $agora,
            ]],
            ['user_id', 'publicacao_id'],
            // Só a ciência muda: `lida_em` é a PRIMEIRA leitura e não se sobrescreve.
            ['ciente_em'],
        );

        return back();
    }

    public function storeAnexo(Request $request, IntranetPublicacao $publicacao): RedirectResponse
    {
        abort_unless($publicacao->podeSerGerenciadaPor($request->user()), 403);

        $request->validate([
            'arquivo' => ['required', 'file', 'max:'.IntranetAnexo::TAMANHO_MAXIMO_KB],
        ], [
            'arquivo.required' => 'Escolha um arquivo.',
            'arquivo.max' => 'O arquivo deve ter no máximo 15 MB.',
            'arquivo.uploaded' => 'O arquivo não chegou ao servidor — provavelmente passou de 15 MB.',
        ]);

        if ($publicacao->anexos()->count() >= self::MAX_ANEXOS) {
            return back()->withErrors(['arquivo' => 'Limite de '.self::MAX_ANEXOS.' anexos por publicação.']);
        }

        $arquivo = $request->file('arquivo');
        [$mime, $extensao] = $this->tipoDoArquivo($arquivo);

        if ($extensao === null) {
            return back()->withErrors(['arquivo' => 'Formato não aceito. Envie PDF, imagem (PNG, JPG, WEBP, GIF), Word, Excel ou PowerPoint.']);
        }

        $caminho = 'intranet/'.Str::uuid()->toString().'.'.$extensao;
        Disco::uploads()->putFileAs('intranet', $arquivo, basename($caminho));

        $publicacao->anexos()->create([
            'nome_original' => Str::limit($arquivo->getClientOriginalName(), 195, ''),
            'caminho' => $caminho,
            'mime' => $mime,
            'tamanho' => $arquivo->getSize(),
            'ordem' => (int) $publicacao->anexos()->max('ordem') + 1,
        ]);

        return back();
    }

    public function destroyAnexo(Request $request, IntranetAnexo $anexo): RedirectResponse
    {
        $publicacao = $anexo->publicacao;
        abort_if($publicacao === null, 404);
        abort_unless($publicacao->podeSerGerenciadaPor($request->user()), 403);

        Disco::uploads()->delete($anexo->caminho);
        $anexo->delete();

        return back();
    }

    /**
     * Download de anexo. Qualquer usuário autenticado — a intranet é de todos —, mas só
     * de publicação que ainda existe: excluída, o arquivo continua no disco e o link some.
     */
    public function baixarAnexo(IntranetAnexo $anexo): HttpResponse
    {
        abort_if($anexo->publicacao === null, 404);

        return Disco::respostaDeDownload($anexo->caminho, $anexo->nome_original);
    }

    /** HTML do markdown para a aba "Pré-visualizar" — o MESMO renderizador da leitura. */
    public function previa(Request $request): JsonResponse
    {
        abort_unless(IntranetPublicacao::podePublicar($request->user()), 403);

        $dados = $request->validate(['corpo' => ['nullable', 'string', 'max:20000']]);

        return response()->json(['html' => IntranetPublicacao::renderizar($dados['corpo'] ?? '')]);
    }

    // ── internos ─────────────────────────────────────────────────────────────────────

    /** @return array{categoria: string, titulo: string, corpo: string, importante: bool, fixada: bool, exige_ciencia: bool} */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'categoria' => ['required', Rule::in(array_keys(IntranetPublicacao::CATEGORIAS))],
            'titulo' => ['required', 'string', 'max:160'],
            'corpo' => ['required', 'string', 'max:20000'],
            'importante' => ['boolean'],
            'fixada' => ['boolean'],
            'exige_ciencia' => ['boolean'],
        ], [
            'categoria.in' => 'Categoria inválida.',
            'titulo.required' => 'Dê um título à publicação.',
            'corpo.required' => 'Escreva o conteúdo da publicação.',
        ]);

        return [
            'categoria' => $dados['categoria'],
            'titulo' => trim($dados['titulo']),
            'corpo' => $dados['corpo'],
            'importante' => (bool) ($dados['importante'] ?? false),
            'fixada' => (bool) ($dados['fixada'] ?? false),
            'exige_ciencia' => (bool) ($dados['exige_ciencia'] ?? false),
        ];
    }

    /**
     * MIME pelo CONTEÚDO do arquivo (`finfo` no tmp), nunca pelo nome nem pelo
     * `Content-Type` que o navegador mandou. Um `.php` renomeado para `.pdf` cai em
     * `text/x-php` e é recusado — o `getMimeType()` do UploadedFile fake do Laravel
     * adivinha pela extensão e deixaria o teste verde com o código errado.
     */
    private function mimeDetectado(\Illuminate\Http\UploadedFile $arquivo): string
    {
        $caminho = $arquivo->getRealPath();
        if (is_string($caminho) && is_file($caminho)) {
            $detectado = (new \finfo(FILEINFO_MIME_TYPE))->file($caminho);
            if (is_string($detectado) && $detectado !== '') {
                return $detectado;
            }
        }

        return (string) $arquivo->getMimeType();
    }

    /**
     * MIME detectado no servidor → extensão. Nunca a extensão enviada pelo navegador.
     *
     * A única concessão é o OOXML (docx/xlsx/pptx): conforme a versão da libmagic, o `finfo`
     * enxerga só o contêiner zip. Aí — e só aí — a extensão do cliente decide ENTRE os três
     * formatos do Office; um zip qualquer renomeado para `.docx` passa, mas continua sendo
     * servido como download, nunca executado nem exibido inline.
     *
     * @return array{0: string, 1: ?string}
     */
    private function tipoDoArquivo(\Illuminate\Http\UploadedFile $arquivo): array
    {
        $mime = $this->mimeDetectado($arquivo);

        if (isset(IntranetAnexo::TIPOS[$mime])) {
            return [$mime, IntranetAnexo::TIPOS[$mime]];
        }

        $extensaoCliente = strtolower($arquivo->getClientOriginalExtension());

        if (in_array($mime, ['application/zip', 'application/octet-stream'], true)
            && in_array($extensaoCliente, IntranetAnexo::EXTENSOES_OFFICE, true)) {
            $mimeOficial = array_search($extensaoCliente, IntranetAnexo::TIPOS, true);

            return [(string) $mimeOficial, $extensaoCliente];
        }

        return [$mime, null];
    }

    private function dadosBasicos(IntranetPublicacao $p): array
    {
        return [
            'id' => $p->id,
            'categoria' => $p->categoria,
            'categoriaRotulo' => IntranetPublicacao::rotuloDaCategoria($p->categoria),
            'titulo' => $p->titulo,
            'autor' => $this->nomeDe($p->autor),
            'publicadaEm' => $p->publicada_em->toIso8601String(),
            'editadaEm' => $p->editada_em?->toIso8601String(),
            'importante' => $p->importante,
            'fixada' => $p->fixada,
            'exigeCiencia' => $p->exige_ciencia,
        ];
    }

    private function dadosDoAnexo(IntranetAnexo $a): array
    {
        return [
            'id' => $a->id,
            'nome' => $a->nome_original,
            'tamanho' => $a->tamanho,
            'ehImagem' => $a->ehImagem(),
            'download' => route('intranet.anexos.baixar', $a->id),
        ];
    }

    /**
     * Quem já deu ciência e quem falta. O universo é todo usuário ATIVO menos o autor —
     * quem escreveu a regra não precisa concordar com ela.
     *
     * ⚠️ Os NOMES de quem falta, não só a contagem: "12 de 200" não diz a quem cobrar.
     *
     * @return array{cientes: int, total: int, pendentes: list<string>}
     */
    private function painelDeCiencia(IntranetPublicacao $publicacao): array
    {
        $universo = User::query()
            ->where('is_active', true)
            ->whereKeyNot($publicacao->user_id);

        $total = (clone $universo)->count();

        $pendentes = (clone $universo)
            ->whereNotExists(function ($q) use ($publicacao) {
                $q->selectRaw('1')
                    ->from('intranet_leituras')
                    ->whereColumn('intranet_leituras.user_id', 'users.id')
                    ->where('intranet_leituras.publicacao_id', $publicacao->id)
                    ->whereNotNull('intranet_leituras.ciente_em');
            })
            ->orderBy('name')
            ->get(['name', 'display_name'])
            ->map(fn (User $u) => $u->display_name ?: $u->name)
            ->values()
            ->all();

        return [
            'cientes' => $total - count($pendentes),
            'total' => $total,
            'pendentes' => $pendentes,
        ];
    }

    private function limitesDeAnexo(): array
    {
        return [
            'maxAnexos' => self::MAX_ANEXOS,
            'tamanhoMaximoMb' => intdiv(IntranetAnexo::TAMANHO_MAXIMO_KB, 1024),
            'aceitos' => '.pdf,.png,.jpg,.jpeg,.webp,.gif,.docx,.xlsx,.pptx',
        ];
    }

    private function nomeDe(?User $user): string
    {
        return $user?->display_name ?: ($user?->name ?? '—');
    }

    private function simulando(Request $request): bool
    {
        return $request->session()->has(SimulacaoController::SESSAO_ADMIN_ID);
    }
}
