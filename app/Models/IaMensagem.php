<?php

namespace App\Models;

use App\Support\Markdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class IaMensagem extends Model
{
    protected $table = 'ia_mensagens';

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_OK = 'ok';

    public const STATUS_ERRO = 'erro';

    /**
     * O usuário apertou "Parar". A pergunta e a resposta ficam fora do histórico: se o
     * modelo entendeu errado, a tentativa descartada só atrapalharia a próxima.
     */
    public const STATUS_CANCELADA = 'cancelada';

    /** Quanto vale o pedido de parada no Redis (chave com TTL — nunca `Cache::forever`). */
    private const MINUTOS_PEDIDO_DE_PARADA = 30;

    /**
     * Resposta parada em "processando" além disto é tratada como interrompida (worker
     * morto, deploy no meio). Mesmo desenho das exportações e das cotações. Acompanha o
     * teto do job: com modelo local na CPU, uma resposta legítima passa de 5 minutos.
     */
    public static function minutosTravada(): int
    {
        return max(5, (int) ceil(config('ia.timeout_job') / 60) + 1);
    }

    /**
     * Avisa o job em andamento que o usuário desistiu. É pelo Redis, e não pelo banco,
     * porque o job confere isso a cada meio segundo enquanto o texto chega.
     */
    public function pedirParada(): void
    {
        Cache::put(self::chaveParada($this->id), true, now()->addMinutes(self::MINUTOS_PEDIDO_DE_PARADA));
    }

    public static function paradaPedida(int $id): bool
    {
        return Cache::has(self::chaveParada($id));
    }

    private static function chaveParada(int $id): string
    {
        return "ia:parar:{$id}";
    }

    protected $fillable = [
        'conversa_id', 'papel', 'texto', 'conteudo_api', 'ferramentas', 'status', 'erro', 'tela',
        'tokens_entrada', 'tokens_saida', 'tokens_cache_leitura', 'tokens_cache_escrita',
        'custo_usd', 'latencia_ms', 'avaliacao',
    ];

    protected function casts(): array
    {
        return [
            'conteudo_api' => 'array',
            'ferramentas' => 'array',
            'custo_usd' => 'decimal:6',
        ];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(IaConversa::class, 'conversa_id');
    }

    public function travou(): bool
    {
        return $this->status === self::STATUS_PROCESSANDO
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::minutosTravada()));
    }

    /** O formato que o painel do chat recebe — o mesmo no histórico e no polling. */
    public function paraTela(): array
    {
        $status = $this->travou() ? self::STATUS_ERRO : $this->status;

        return [
            'id' => $this->id,
            'papel' => $this->papel,
            'texto' => $this->texto,
            'html' => $this->papel === 'ia' && $status === self::STATUS_OK && $this->texto !== null
                ? Markdown::seguro($this->texto)
                : null,
            'status' => $status,
            'erro' => $status === self::STATUS_ERRO
                ? ($this->erro ?: 'A resposta foi interrompida. Tente perguntar de novo.')
                : null,
            'avaliacao' => $this->avaliacao,
        ];
    }
}
