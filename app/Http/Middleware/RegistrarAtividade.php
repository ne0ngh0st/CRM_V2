<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SimulacaoController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca presença do usuário — é o que alimenta a badge "N online agora" e o filtro
 * "Presença" da tela Equipe, escrevendo users.last_activity_at.
 *
 * ⚠️ Até 2026-08-31 NADA no CRM-V2 escrevia essa coluna. O ImportUsuariosLegado a
 * passava num fill(), mas ela não está (nem deve estar) no $fillable do User, então
 * a escrita era descartada em silêncio: em produção a coluna era NULL para os 201
 * usuários, a badge dizia "0 online agora" para sempre e o filtro nunca devolvia
 * ninguém. Não era erro de contagem — era ausência de fonte de dado.
 *
 * ⚠️ Regra de ouro nº 9 (latência): a escrita acontece em terminate(), depois de a
 * resposta já ter sido entregue ao cliente, e passa por um trinco no Redis que limita
 * a UM UPDATE por usuário a cada JANELA_ESCRITA_SEGUNDOS. O que entra no caminho do
 * request é um comando do Redis, não um UPDATE por página aberta.
 *
 * Regra de ouro nº 8: este é o ÚNICO lugar que escreve presença. Sair do sistema não
 * zera a coluna de propósito — quem desloga simplesmente para de gerar atividade e
 * cai da lista quando a janela de EquipeController::MINUTOS_ONLINE expira.
 */
class RegistrarAtividade
{
    /** Teto de um UPDATE por usuário nesta janela. Menor que a de "online" da Equipe. */
    private const JANELA_ESCRITA_SEGUNDOS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $id = $this->usuarioPresente($request);

        if ($id === null) {
            return;
        }

        // Cache::add só grava se a chave ainda não existe — é o trinco, atômico no
        // Redis. Chave com TTL, nunca Cache::forever (política volatile-lru do projeto);
        // se ela for descartada sob pressão de memória o pior caso é um UPDATE a mais.
        if (! Cache::add("presenca:{$id}", true, self::JANELA_ESCRITA_SEGUNDOS)) {
            return;
        }

        // DB::table e não o model, de propósito: presença não é edição de cadastro, então
        // não deve mexer em users.updated_at nem disparar evento/observer de model.
        DB::table('users')->where('id', $id)->update(['last_activity_at' => now()]);
    }

    /**
     * Durante uma simulação, quem está de fato usando o sistema é o ADMIN — o guard
     * devolve o alvo, mas marcar o alvo mostraria "online agora", para a equipe
     * inteira, alguém que pode estar em casa. Presença segue a pessoa, não o guard.
     */
    private function usuarioPresente(Request $request): ?int
    {
        if ($request->hasSession() && $request->session()->has(SimulacaoController::SESSAO_ADMIN_ID)) {
            return (int) $request->session()->get(SimulacaoController::SESSAO_ADMIN_ID);
        }

        return $request->user()?->id;
    }
}
