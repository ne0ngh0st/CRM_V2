<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacao extends Model
{
    protected $table = 'notificacoes';

    /**
     * Tipos cujo `link` aponta para um ARQUIVO, não para uma página do sistema.
     *
     * ⚠️ Isto não é rotulagem: é o que decide COMO o sino abre o link. O clique padrão usa
     * o router do Inertia, que faz um XHR esperando uma resposta de página; recebendo um
     * .xlsx ele descarta a resposta e o clique não faz absolutamente nada — sem erro na
     * tela, sem download. Foi exatamente o que aconteceu com a planilha da Carteira. Tipo
     * listado aqui é entregue ao navegador, que baixa o arquivo.
     *
     * Toda notificação nova cujo link devolva arquivo (PDF, planilha) entra aqui.
     */
    public const TIPOS_DOWNLOAD = ['exportacao_pronta'];

    protected $fillable = [
        'user_id',
        'tipo',
        'titulo',
        'mensagem',
        'link',
        'referencia_tipo',
        'referencia_id',
        'lida_em',
    ];

    protected function casts(): array
    {
        return [
            'lida_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeNaoLidas(Builder $query): Builder
    {
        return $query->whereNull('lida_em');
    }

    public function ehDownload(): bool
    {
        return in_array($this->tipo, self::TIPOS_DOWNLOAD, true);
    }

    /**
     * Payload que o sino consome — usado pelo histórico (NotificacaoController::index) e
     * pelo tempo real (NotificacaoCriada::broadcastWith).
     *
     * ⚠️ Um lugar só de propósito (Regra de ouro nº 8): os dois caminhos alimentam a MESMA
     * lista no front. Com duas cópias, um campo novo entra em um e não no outro, e a
     * notificação passa a se comportar diferente conforme tenha chegado ao vivo ou depois
     * de um F5 — divergência que não quebra nada em vermelho e ninguém liga uma coisa na
     * outra.
     *
     * @return array{id: int, tipo: string, titulo: string, mensagem: ?string, link: ?string, download: bool, criadoEm: string}
     */
    public function paraOSino(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'mensagem' => $this->mensagem,
            'link' => $this->link,
            'download' => $this->ehDownload(),
            'criadoEm' => $this->created_at->toIso8601String(),
        ];
    }
}
