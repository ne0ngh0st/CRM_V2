<?php

namespace App\Events;

use App\Models\Notificacao;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificacaoCriada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Notificacao $notificacao)
    {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->notificacao->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notificacao.criada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificacao->id,
            'tipo' => $this->notificacao->tipo,
            'titulo' => $this->notificacao->titulo,
            'mensagem' => $this->notificacao->mensagem,
            'link' => $this->notificacao->link,
            'criadoEm' => $this->notificacao->created_at->toIso8601String(),
        ];
    }
}
