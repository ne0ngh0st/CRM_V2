<?php

namespace App\Http\Controllers;

use App\Models\SimulacaoUsuario;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Simulação de usuário ("ver o sistema pelos olhos de X").
 *
 * Por que funciona em toda página, diferente do legado: aqui a identidade tem uma fonte
 * só — o guard do Laravel. Trocar o usuário autenticado faz TODOS os pontos que chamam
 * `$request->user()` e todos os resolvers de escopo (`DashboardScopeResolver`,
 * `EquipeScopeResolver`, `MetaRankingResolver`, `CarteiraAderenciaResolver`) enxergarem o
 * alvo automaticamente, sem nenhuma página precisar saber que a simulação existe. No
 * legado a identidade era lida de `$_SESSION['usuario']` em 257 arquivos e só 7 sabiam da
 * simulação — daí "só pegava na primeira página".
 *
 * Custo por request: ZERO query. O banner é alimentado por dados que já ficam na sessão
 * (`HandleInertiaRequests`), e não existe middleware novo no caminho. O banco só é tocado
 * nas duas transições (iniciar/encerrar), pra gravar a auditoria.
 */
class SimulacaoController extends Controller
{
    /** Chaves de sessão. `nome` fica guardado pra o banner não custar um SELECT por request. */
    public const SESSAO_ADMIN_ID = 'simulacao_admin_id';

    public const SESSAO_ADMIN_NOME = 'simulacao_admin_nome';

    public const SESSAO_LOG_ID = 'simulacao_log_id';

    public function iniciar(Request $request, User $usuario): RedirectResponse
    {
        $atual = $request->user();

        // Aninhar simulação é proibido (abaixo), então aqui o usuário autenticado é
        // sempre o real — checar o perfil dele basta.
        abort_unless($atual->hasRole('admin'), 403);

        abort_if($request->session()->has(self::SESSAO_ADMIN_ID), 409,
            'Encerre a simulação atual antes de iniciar outra.');
        abort_if($usuario->id === $atual->id, 422, 'Não faz sentido simular você mesmo.');
        abort_if($usuario->hasRole('admin'), 403, 'Não é permitido simular outro admin.');
        abort_unless($usuario->is_active, 422, 'Usuário inativo não pode ser simulado.');

        $log = SimulacaoUsuario::create([
            'admin_id' => $atual->id,
            'alvo_id' => $usuario->id,
            'ip' => $request->ip(),
            'iniciada_em' => now(),
        ]);

        $adminNome = $atual->display_name ?: $atual->name;

        // Troca o usuário autenticado. `Auth::login()` já faz `session()->migrate(true)`
        // internamente (novo id de sessão, dados preservados), então não precisa de
        // regenerate() manual — as chaves gravadas depois disto sobrevivem.
        Auth::login($usuario);

        $request->session()->put(self::SESSAO_ADMIN_ID, $atual->id);
        $request->session()->put(self::SESSAO_ADMIN_NOME, $adminNome);
        $request->session()->put(self::SESSAO_LOG_ID, $log->id);

        return redirect()->route('dashboard');
    }

    /**
     * Encerrar precisa funcionar para o usuário SIMULADO (que normalmente não é admin) —
     * a autorização aqui é "existe simulação nesta sessão", não o perfil.
     */
    public function encerrar(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get(self::SESSAO_ADMIN_ID);

        if ($adminId === null) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);

        // Admin apagado/desativado no meio da simulação: não dá pra voltar — derruba a
        // sessão em vez de deixar o usuário preso na pele de outra pessoa.
        if ($admin === null || ! $admin->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'A simulação foi encerrada porque a conta de origem não está mais disponível.']);
        }

        if ($logId = $request->session()->get(self::SESSAO_LOG_ID)) {
            SimulacaoUsuario::whereKey($logId)->whereNull('encerrada_em')->update(['encerrada_em' => now()]);
        }

        Auth::login($admin);
        $this->limparSessao($request);

        return redirect()->route('equipe.index');
    }

    private function limparSessao(Request $request): void
    {
        $request->session()->forget([
            self::SESSAO_ADMIN_ID,
            self::SESSAO_ADMIN_NOME,
            self::SESSAO_LOG_ID,
        ]);
    }
}
