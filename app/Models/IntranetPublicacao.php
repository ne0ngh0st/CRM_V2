<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Uma publicação da intranet — o "post". Tudo o que a gestão comunica mora aqui, e a
 * CATEGORIA é o que separa aviso de regra de negócio, workflow e documento. A "biblioteca
 * de documentos" não é outra tela: é o filtro "Documento" desta mesma lista.
 */
class IntranetPublicacao extends Model
{
    use SoftDeletes;

    protected $table = 'intranet_publicacoes';

    /**
     * As categorias, com rótulo e o padrão de "pedir ciência".
     *
     * ⚠️ FONTE ÚNICA (Regra de ouro nº 8). O front recebe esta lista pronta como prop — não
     * existe cópia de rótulo em `constants/`. É também a whitelist do filtro e da validação:
     * o valor vem da requisição e vira valor de um enum do MySQL.
     *
     * A categoria só define o PADRÃO da ciência; quem publica pode ligar ou desligar em
     * qualquer uma. Regra de negócio nasce pedindo porque é o caso em que "ninguém me
     * avisou" custa dinheiro.
     */
    public const CATEGORIAS = [
        'aviso' => ['rotulo' => 'Aviso', 'exigeCiencia' => false],
        'regra' => ['rotulo' => 'Regra de negócio', 'exigeCiencia' => true],
        'workflow' => ['rotulo' => 'Workflow', 'exigeCiencia' => false],
        'documento' => ['rotulo' => 'Documento', 'exigeCiencia' => false],
    ];

    /** Quem publica. Todos os outros perfis leem. */
    public const PAPEIS_QUE_PUBLICAM = ['admin', 'diretor', 'supervisor'];

    /** Quem edita e exclui publicação de QUALQUER autor (supervisor só mexe na própria). */
    public const PAPEIS_QUE_GERENCIAM_TODAS = ['admin', 'diretor'];

    protected $fillable = [
        'user_id',
        'categoria',
        'titulo',
        'corpo',
        'importante',
        'fixada',
        'exige_ciencia',
        'revisao_ciencia',
        'publicada_em',
        'editada_em',
    ];

    protected function casts(): array
    {
        return [
            'importante' => 'boolean',
            'fixada' => 'boolean',
            'exige_ciencia' => 'boolean',
            'revisao_ciencia' => 'integer',
            'publicada_em' => 'datetime',
            'editada_em' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(IntranetAnexo::class, 'publicacao_id')->orderBy('ordem')->orderBy('id');
    }

    public function leituras(): HasMany
    {
        return $this->hasMany(IntranetLeitura::class, 'publicacao_id');
    }

    public static function podePublicar(User $user): bool
    {
        return in_array($user->getRoleNames()->first(), self::PAPEIS_QUE_PUBLICAM, true);
    }

    /**
     * Editar, excluir, anexar e ver o painel de ciência.
     *
     * ⚠️ Um método só, usado pelo controller E pela prop `pode` do front. Com a regra em
     * dois lugares, o botão de editar apareceria para quem o servidor recusa — ou pior, o
     * contrário.
     */
    public function podeSerGerenciadaPor(User $user): bool
    {
        $papel = $user->getRoleNames()->first();

        if (in_array($papel, self::PAPEIS_QUE_GERENCIAM_TODAS, true)) {
            return true;
        }

        return $papel === 'supervisor' && $this->user_id === $user->id;
    }

    public static function rotuloDaCategoria(string $categoria): string
    {
        return self::CATEGORIAS[$categoria]['rotulo'] ?? $categoria;
    }

    /** A lista pronta para o front: `[{valor, rotulo, exigeCiencia}]`, na ordem declarada. */
    public static function categoriasParaOFront(): array
    {
        return collect(self::CATEGORIAS)
            ->map(fn (array $c, string $valor) => ['valor' => $valor, ...$c])
            ->values()
            ->all();
    }

    /**
     * Fixadas primeiro, depois as mais recentes. Coberta pelo índice da migration.
     *
     * ⚠️ Colunas QUALIFICADAS: a listagem faz join com `intranet_leituras`, que também tem
     * `id`, e um `ORDER BY id` solto ali é ambíguo e estoura.
     */
    public function scopeOrdemDaLista(Builder $query): Builder
    {
        return $query
            ->orderByDesc($query->qualifyColumn('fixada'))
            ->orderByDesc($query->qualifyColumn('publicada_em'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * O corpo em HTML, pronto para `v-html`.
     *
     * ⚠️ `html_input => strip` e `allow_unsafe_links => false` SÃO a segurança desta
     * feature: é o que torna seguro o front injetar o HTML. Quem publica é gestor, mas o
     * texto é lido pelos ~200 usuários — um `<script>` colado de algum lugar rodaria na
     * sessão de todos. Renderizar no servidor mantém a regra num lugar só e dispensa
     * biblioteca de markdown no bundle.
     */
    public static function renderizar(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /** Resumo em texto puro para o cartão da lista (sem markdown cru aparecendo). */
    public function resumo(int $limite = 220): string
    {
        $texto = strip_tags(self::renderizar($this->corpo));
        $texto = trim(preg_replace('/\s+/u', ' ', html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return Str::limit($texto, $limite);
    }

    /**
     * As duas contagens da faixa do Painel, numa query só.
     *
     * ⚠️ SEM CACHE, de propósito: o número tem que zerar assim que a pessoa lê. É barato
     * porque a tabela tem centenas de linhas, não milhões — e o `NOT EXISTS` bate direto
     * no unique `(user_id, publicacao_id)` de `intranet_leituras`.
     *
     * "Não lida" = nunca abriu. "Ciência pendente" = pede ciência e ainda não foi dada,
     * mesmo que já tenha aberto — ler e concordar são coisas diferentes.
     *
     * @return array{naoLidas: int, cienciasPendentes: int}
     */
    public static function contagensPara(User $user): array
    {
        $linha = self::query()
            ->leftJoin('intranet_leituras as l', function ($join) use ($user) {
                $join->on('l.publicacao_id', '=', 'intranet_publicacoes.id')
                    ->where('l.user_id', '=', $user->id);
            })
            ->selectRaw('COALESCE(SUM(l.id IS NULL), 0) AS nao_lidas')
            ->selectRaw('COALESCE(SUM(intranet_publicacoes.exige_ciencia = 1 AND l.ciente_em IS NULL), 0) AS ciencias')
            ->first();

        return [
            'naoLidas' => (int) ($linha->nao_lidas ?? 0),
            'cienciasPendentes' => (int) ($linha->ciencias ?? 0),
        ];
    }

    /**
     * Grava a leitura. Idempotente: abrir de novo não mexe em nada (o unique segura).
     */
    public function registrarLeitura(User $user): void
    {
        DB::table('intranet_leituras')->insertOrIgnore([
            'publicacao_id' => $this->id,
            'user_id' => $user->id,
            'lida_em' => now(),
        ]);
    }
}
