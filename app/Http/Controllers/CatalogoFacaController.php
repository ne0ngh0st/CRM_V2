<?php

namespace App\Http\Controllers;

use App\Models\Faca;
use App\Models\FacaRecurso;
use App\Support\Uploads\Disco;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CatalogoFacaController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $busca = trim((string) $request->string('busca'));
        $tipo = trim((string) $request->string('tipo'));
        if (! array_key_exists($tipo, Faca::TIPOS)) {
            $tipo = '';
        }

        $facas = $this->listaQuery($request)
            ->with('recursos')
            ->get()
            ->map(fn (Faca $faca) => [
                'id' => $faca->id,
                'tipo' => $faca->tipo,
                'tipoNome' => $faca->tipo_nome,
                'item' => $faca->item,
                'largura' => $faca->largura,
                'altura' => $faca->altura,
                'observacao' => $faca->observacao,
                'recursos' => $faca->recursos->map(fn (FacaRecurso $r) => [
                    'id' => $r->id,
                    'descricao' => $r->descricao,
                    'imagem' => $this->urlDaImagem($r->imagem),
                ])->values(),
            ]);

        // Contagem por tipo pro filtro — sem o filtro de tipo aplicado, senão o dropdown
        // zeraria todo mundo que não é o tipo selecionado.
        $porTipo = Faca::query()
            ->select('tipo', DB::raw('COUNT(*) as total'))
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $tipos = collect(Faca::TIPOS)
            ->map(fn (string $nome, string $slug) => [
                'slug' => $slug,
                'nome' => $nome,
                'total' => (int) ($porTipo[$slug] ?? 0),
            ])
            ->values()
            ->filter(fn (array $t) => $t['total'] > 0)
            ->values();

        return Inertia::render('Catalogo/Facas', [
            'role' => $role,
            'podeGerenciar' => $role === 'admin',
            'facas' => $facas,
            'kpis' => [
                'total' => (int) $porTipo->sum(),
                'catalogos' => $tipos->count(),
                'filtradas' => $facas->count(),
            ],
            'filtros' => [
                'busca' => $busca,
                'tipo' => $tipo,
            ],
            'opcoes' => [
                // Filtro: só catálogos que têm faca. Formulário: todos, senão não dá
                // pra cadastrar a primeira faca de um catálogo que ficou vazio.
                'tipos' => $tipos,
                'todosTipos' => collect(Faca::TIPOS)
                    ->map(fn (string $nome, string $slug) => ['slug' => $slug, 'nome' => $nome])
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $faca = Faca::create($this->validarFaca($request));

        // A tela usa esse id pra subir, na sequência, as imagens que o admin escolheu
        // antes da faca existir — sem depender de reencontrá-la na listagem filtrada.
        return back()->with('recursoCriadoId', $faca->id);
    }

    public function update(Request $request, Faca $faca): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $faca->update($this->validarFaca($request, $faca));

        return back();
    }

    public function destroy(Request $request, Faca $faca): RedirectResponse
    {
        $this->autorizarAdmin($request);

        foreach ($faca->recursos as $recurso) {
            $this->apagarImagemEnviada($recurso);
        }

        // faca_recursos cai junto pela FK (cascadeOnDelete).
        $faca->delete();

        return back();
    }

    public function storeRecurso(Request $request, Faca $faca): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $data = $request->validate([
            'descricao' => ['nullable', 'string', 'max:255'],
            'imagem' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120'],
        ], [
            'imagem.image' => 'O arquivo deve ser uma imagem.',
            'imagem.mimes' => 'Formatos aceitos: JPG, PNG, GIF ou WEBP.',
            'imagem.max' => 'A imagem deve ter no máximo 5 MB.',
        ]);

        if (($data['descricao'] ?? null) === null && ! $request->hasFile('imagem')) {
            return back()->withErrors(['descricao' => 'Informe uma descrição ou envie uma imagem.']);
        }

        $faca->recursos()->create([
            'descricao' => $data['descricao'] ?? null,
            'imagem' => $request->hasFile('imagem') ? $this->guardarImagem($request) : null,
            'ordem' => (int) $faca->recursos()->max('ordem') + 1,
        ]);

        return back();
    }

    public function destroyRecurso(Request $request, FacaRecurso $recurso): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $this->apagarImagemEnviada($recurso);
        $recurso->delete();

        return back();
    }

    /**
     * Uploads vão para o DISCO DE UPLOADS (local em dev, S3 em produção), nunca para
     * `public/images/facas`.
     *
     * As imagens de `public/` vieram do legado e são versionadas no git. Dois motivos
     * para o upload não ir para lá: no Forge o diretório do código é recriado a cada
     * release, e com dois app nodes o arquivo gravado no disco de um não existe no outro
     * — a imagem quebraria em cerca de metade dos carregamentos.
     *
     * Ver a classe Disco e docs/deploy-aws.md, seção 5.4.
     */
    private function guardarImagem(Request $request): string
    {
        $arquivo = $request->file('imagem');

        // Extensão derivada do MIME detectado no servidor, nunca do nome enviado pelo
        // cliente — mesmo cuidado que o ProfileController::updateFoto já faz.
        $extensaoPorMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extensao = $extensaoPorMime[$arquivo->getMimeType()] ?? null;
        abort_if($extensao === null, 422, 'Formato de imagem não suportado.');

        $nome = Str::uuid()->toString().'.'.$extensao;
        $arquivo->storeAs('facas', $nome, Disco::nomeUploads());

        // Guarda o CAMINHO NO DISCO ('facas/x.png'), não uma URL pronta. A URL é
        // derivada na leitura por urlDaImagem(), porque ela muda conforme o disco:
        // '/storage/facas/x.png' em dev, uma URL do S3 em produção.
        return 'facas/'.$nome;
    }

    /**
     * Resolve a URL de exibição, cobrindo os TRÊS formatos que existem na coluna.
     *
     * Nenhuma migration foi feita de propósito: os registros antigos continuam válidos e
     * o custo é este método, que é barato e explícito.
     *
     *  1. `images/facas/...`  → asset versionado no repositório, veio do legado
     *  2. `storage/facas/...` → upload gravado antes de 2026-08-27 (formato antigo)
     *  3. `facas/...`         → upload novo: caminho no disco de uploads
     */
    private function urlDaImagem(?string $imagem): ?string
    {
        if ($imagem === null || $imagem === '') {
            return null;
        }

        if (str_starts_with($imagem, 'images/') || str_starts_with($imagem, 'storage/')) {
            return '/'.ltrim($imagem, '/');
        }

        return Disco::uploads()->url($imagem);
    }

    /** Só remove arquivo que foi enviado pela tela; imagem do legado é versionada. */
    private function apagarImagemEnviada(FacaRecurso $recurso): void
    {
        $imagem = $recurso->imagem;

        if ($imagem === null || str_starts_with($imagem, 'images/')) {
            return; // asset do repositório: não se mexe
        }

        // Formato antigo guardava a URL ('storage/facas/x.png'); o novo guarda o caminho.
        $caminho = str_starts_with($imagem, 'storage/')
            ? Str::after($imagem, 'storage/')
            : $imagem;

        Disco::uploads()->delete($caminho);
    }

    /** @return array<string, mixed> */
    private function validarFaca(Request $request, ?Faca $faca = null): array
    {
        return $request->validate([
            'tipo' => ['required', 'string', Rule::in(array_keys(Faca::TIPOS))],
            'item' => [
                'required', 'integer', 'min:1', 'max:9999',
                // Item é o número dentro do catálogo — repetir confunde quem consulta.
                Rule::unique('facas', 'item')
                    ->where(fn ($q) => $q->where('tipo', $request->input('tipo')))
                    ->ignore($faca?->id),
            ],
            'largura' => ['nullable', 'string', 'max:20'],
            'altura' => ['nullable', 'string', 'max:20'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ], [
            'item.unique' => 'Já existe uma faca com esse número neste catálogo.',
        ]);
    }

    private function autorizarAdmin(Request $request): void
    {
        abort_unless($request->user()->getRoleNames()->first() === 'admin', 403);
    }

    /**
     * Catálogo é igual pra todo mundo — sem escopo por perfil, igual à Tabela de Preços.
     * São 127 facas no total, então a página carrega tudo de uma vez (sem paginação).
     */
    protected function listaQuery(Request $request): Builder
    {
        $busca = trim((string) $request->string('busca'));
        $tipo = trim((string) $request->string('tipo'));

        $query = Faca::query()->orderBy('tipo')->orderBy('item');

        if (array_key_exists($tipo, Faca::TIPOS)) {
            $query->where('tipo', $tipo);
        }

        if ($busca !== '') {
            $query->where(function (Builder $q) use ($busca) {
                $q->where('largura', 'like', "%{$busca}%")
                    ->orWhere('altura', 'like', "%{$busca}%")
                    ->orWhere('observacao', 'like', "%{$busca}%")
                    ->orWhereRaw("CONCAT(largura, 'x', altura) like ?", ["%{$busca}%"])
                    ->orWhereHas('recursos', fn (Builder $r) => $r->where('descricao', 'like', "%{$busca}%"));
            });
        }

        return $query;
    }
}
