<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma planilha pedida por um usuário — gerada na hora ou em segundo plano.
 *
 * Ver a migration `create_exportacoes_table` para o porquê de a geração poder ir para a
 * fila, e `config/exportacoes.php` para o prazo de validade e o corte de volume.
 *
 * ⚠️ TODA planilha do sistema passa por aqui desde 2026-09-09, inclusive as que baixam
 * na hora. Antes só a Carteira registrava, e as outras oito sumiam no histórico do
 * navegador de quem clicou — sem lugar nenhum para rebaixar depois que a notificação
 * saía do sino. Era o problema que originou a central de downloads.
 */
class Exportacao extends Model
{
    use HasFactory;

    protected $table = 'exportacoes';

    protected $fillable = [
        'user_id', 'recurso', 'filtros', 'status',
        'caminho', 'nome_arquivo', 'linhas', 'bytes', 'erro', 'expira_em', 'modo_visao',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
            'linhas' => 'integer',
            'bytes' => 'integer',
            'expira_em' => 'datetime',
        ];
    }

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_PRONTO = 'pronto';

    public const STATUS_ERRO = 'erro';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O arquivo pode ser baixado?
     *
     * ⚠️ Checa a validade além do status: um registro `pronto` cujo arquivo já foi
     * expurgado do disco continuaria oferecendo um download que falharia.
     */
    public function disponivel(): bool
    {
        return $this->status === self::STATUS_PRONTO
            && $this->caminho !== null
            && ! $this->expirou();
    }

    /**
     * Passou do prazo — o arquivo ou já foi apagado, ou será na próxima varredura.
     *
     * ⚠️ Um registro `pronto` e vencido NÃO é erro: é o fim de vida normal de uma
     * planilha. A central mostra "expirado" e oferece gerar de novo, em vez de esconder
     * a linha — sumir com o histórico faria o usuário achar que nunca exportou aquilo.
     */
    public function expirou(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }

    /** A fila ainda está trabalhando nesta planilha. */
    public function processando(): bool
    {
        return $this->status === self::STATUS_PROCESSANDO;
    }

    /**
     * Tempo além do qual "processando" deixa de ser espera e passa a ser abandono.
     *
     * O job tem `timeout = 600` (10 min) e uma tentativa só, então nada legítimo continua
     * processando depois disso — com folga larga para uma fila congestionada.
     */
    public const MINUTOS_ATE_ORFA = 60;

    /**
     * 🔴 Ficou órfã: o worker morreu com o job na mão e ninguém mais vai terminá-lo.
     *
     * ⚠️ NÃO É HIPÓTESE — aconteceu em 2026-09-09, durante a construção desta tela. O
     * container `queue` do dev morreu com `ProcessTimedOutException` (o timeout de 700 s do
     * `queue:listen`, já documentado), e como o Redis daqui não tem persistência o job
     * evaporou junto. O `failed()` do job não salva nesse caso: ele só roda se o worker
     * estiver vivo para chamá-lo. O registro ficaria "Preparando" indefinidamente e o
     * usuário esperando um arquivo que ninguém está gerando — a mesma família de falha
     * silenciosa da fila parada de 29/08 e da badge "0 online" de 31/08.
     */
    public function travou(): bool
    {
        return $this->processando()
            && $this->created_at !== null
            && $this->created_at->diffInMinutes(now()) >= self::MINUTOS_ATE_ORFA;
    }

    /**
     * As exportações de uma pessoa, da mais nova para a mais velha.
     *
     * ⚠️ Sempre escopado por dono, nunca por perfil: um .xlsx da Carteira contém a base
     * inteira de clientes de alguém, e nem admin abre a exportação de outro (a mesma
     * regra que o ExportacaoController::download aplica no arquivo).
     *
     * Coberto pelo índice `(user_id, created_at)` criado com a tabela.
     */
    public function scopeDoUsuario(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->latest();
    }
}
