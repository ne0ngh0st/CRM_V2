<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * O andamento de uma resposta do assistente, ao vivo, no canal privado do usuário — o
 * mesmo que o sino já escuta.
 *
 * ⚠️ `ShouldBroadcastNow`, não `ShouldBroadcast`: o segundo enfileira o envio na fila
 * `default`, atrás de exportação e import, e o texto chegaria na tela depois da resposta
 * já pronta. Quem dispara isto já é o job da fila `ia`.
 *
 * Tipos: `delta` (texto acumulado até agora), `ferramenta` (rótulo do que está
 * consultando), `concluida` (mensagem final, mesmo formato do polling) e `erro`.
 * O `delta` manda o texto INTEIRO até ali, não só o pedaço novo: evento perdido não
 * deixa buraco na tela.
 */
class IaEvento implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array<string, mixed> $dados */
    public function __construct(
        public readonly int $userId,
        public readonly int $mensagemId,
        public readonly string $tipo,
        public readonly array $dados = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'ia.evento';
    }

    public function broadcastWith(): array
    {
        return ['mensagemId' => $this->mensagemId, 'tipo' => $this->tipo, ...$this->dados];
    }
}
