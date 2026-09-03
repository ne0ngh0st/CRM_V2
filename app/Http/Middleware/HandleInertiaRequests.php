<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SimulacaoController;
use App\Services\Escopo\ModoVisao;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            // Usado por telas que precisam saber o id do registro recém-criado pra
            // continuar operando nele (ex.: anexar imagens à faca logo após cadastrar).
            'flash' => [
                'recursoCriadoId' => fn () => $request->session()->get('recursoCriadoId'),
            ],
            // Banner de simulação de usuário. Lê SÓ da sessão (o nome do admin é gravado
            // lá no início) — nenhuma query por request, em nenhuma página.
            'simulacao' => [
                'ativa' => $request->session()->has(SimulacaoController::SESSAO_ADMIN_ID),
                'adminNome' => $request->session()->get(SimulacaoController::SESSAO_ADMIN_NOME),
            ],
            /*
             * Alternador "Equipe / Minha carteira" do supervisor. Mesmo princípio do
             * banner de simulação: lê SÓ da sessão, nenhuma query por request.
             *
             * ⚠️ `disponivel` é decidido pelo PAPEL, não pela existência de cod_vendedor.
             * Checar `vendedorPerfil` aqui custaria +1 query em TODA requisição de TODO
             * usuário só para desenhar um botão que 5 pessoas veem (Regra de ouro nº 9).
             * O código é validado no endpoint, que é onde a decisão importa.
             *
             * ⚠️ O NOME É `modoVisao`, NÃO `visao`. Seis páginas (Carteira, Painel, Leads,
             * Orçamentos e as duas de Pedidos) já mandam uma prop de PÁGINA chamada
             * `visao`, com os dropdowns de supervisor/vendedor — e no Inertia a prop de
             * página sobrescreve a compartilhada. Com o nome colidindo, o alternador
             * simplesmente NÃO APARECIA nessas telas, sem erro nenhum no console.
             * Descoberto abrindo a Carteira no navegador; nenhum teste de escopo pegaria,
             * porque o escopo do servidor estava certo — quem sumia era o botão.
             */
            'modoVisao' => [
                'disponivel' => ($request->user()?->getRoleNames()->first()) === 'supervisor',
                'modo' => $request->session()->get(ModoVisao::CHAVE_SESSAO, ModoVisao::EQUIPE),
            ],
        ];
    }
}
