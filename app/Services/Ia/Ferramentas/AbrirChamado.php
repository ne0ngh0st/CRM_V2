<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\IaChamado;
use App\Models\User;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Support\Facades\Log;

/**
 * Registra um chamado para o suporte (o Tony, `config('ia.chamados_para')`) com o
 * contexto da conversa, e avisa pelo sino.
 *
 * É a única ferramenta que ESCREVE — e escreve só no próprio CRM, nunca no TOTVS
 * (Regra de ouro nº 4). O prompt manda chamar só depois que o usuário concordar.
 */
final class AbrirChamado implements Ferramenta
{
    /** Teto por usuário por dia: um laço do modelo não pode virar 30 chamados. */
    private const MAXIMO_POR_DIA = 5;

    public function __construct(private readonly NotificacaoService $notificacoes) {}

    public function nome(): string
    {
        return 'abrir_chamado';
    }

    public function rotuloProgresso(): string
    {
        return 'Abrindo o chamado…';
    }

    public function descricao(): string
    {
        return 'Abre um chamado para o suporte do CRM com um resumo do problema. Use SOMENTE '
            .'depois que o usuário pedir ou concordar, e quando não for possível resolver na '
            .'conversa (erro do sistema, dado que parece errado mesmo atualizado, acesso). O '
            .'resumo deve dizer o que o usuário tentou, o que esperava, o que aconteceu e '
            .'números de pedido/cliente envolvidos.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categoria' => ['type' => 'string', 'enum' => array_keys(IaChamado::CATEGORIAS)],
                'resumo' => ['type' => 'string', 'description' => 'Resumo objetivo, em português, para o suporte.'],
            ],
            'required' => ['categoria', 'resumo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $hoje = IaChamado::query()
            ->where('user_id', $contexto->user->id)
            ->where('created_at', '>=', today())
            ->count();

        if ($hoje >= self::MAXIMO_POR_DIA) {
            return ['aberto' => false, 'mensagem' => 'Limite de chamados por dia atingido. Fale com o suporte diretamente.'];
        }

        $categoria = array_key_exists($entrada['categoria'] ?? '', IaChamado::CATEGORIAS) ? $entrada['categoria'] : 'duvida';

        $chamado = IaChamado::create([
            'user_id' => $contexto->user->id,
            'conversa_id' => $contexto->conversa->id,
            'categoria' => $categoria,
            'resumo' => mb_substr(trim((string) ($entrada['resumo'] ?? '')), 0, 4000),
            'tela' => $contexto->tela,
        ]);

        $suporte = User::query()->where('email', config('ia.chamados_para'))->first();

        if ($suporte === null) {
            Log::warning('ia: chamado sem destinatário', ['chamado' => $chamado->id, 'email' => config('ia.chamados_para')]);
        } else {
            $this->notificacoes->notificar(
                $suporte,
                'ia_chamado',
                'Chamado #'.$chamado->id.' — '.$chamado->rotuloCategoria(),
                ($contexto->user->display_name ?: $contexto->user->name).': '.$chamado->resumo,
                route('ia.admin', ['chamado' => $chamado->id]),
                'ia_chamado',
                $chamado->id,
            );
        }

        return [
            'aberto' => true,
            'protocolo' => $chamado->id,
            'mensagem' => 'Chamado registrado. O suporte responde pelo sino de notificações.',
        ];
    }
}
