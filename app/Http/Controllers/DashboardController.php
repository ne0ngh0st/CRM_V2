<?php

namespace App\Http\Controllers;

use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home do sistema.
 *
 * As agregações vivem em {@see DashboardBlocos} — o controller só resolve o escopo de
 * quem está olhando e monta a resposta. A extração existe para que o job de cache
 * warming chame exatamente os mesmos métodos, com as mesmas chaves.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly DashboardBlocos $blocos,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $codVendedores = $scope['codVendedores'];
        $usuarioIds = $this->scopeResolver->usuarioIds($user, $scope);

        // Assistente não tem escopo comercial; escopo vazio (`[]`) significa vendedor sem
        // código de vendedor cadastrado — nos dois casos os blocos escopados ficam nulos.
        $temEscopo = $codVendedores === null || count($codVendedores) > 0;
        $mostraBlocos = $role !== 'assistente';
        $eGestor = in_array($role, ['supervisor', 'admin', 'diretor'], true);

        $porVendedor = ChaveEscopo::deCodVendedores($codVendedores);
        $porUsuario = ChaveEscopo::deUsuarioIds($usuarioIds);

        return Inertia::render('Dashboard', [
            'role' => $role,
            'statusSistema' => $this->blocos->statusSistema(),
            // Só admin: "cache aquecido" é informação de operação, não de negócio — para
            // um vendedor seria jargão sem significado ocupando espaço no topo da tela.
            'statusCache' => $role === 'admin' ? $this->blocos->statusCache() : null,
            'visao' => [
                'mostrarSeletor' => $eGestor,
                'supervisores' => in_array($role, ['admin', 'diretor'], true) ? $this->scopeResolver->opcoesSupervisores() : [],
                'vendedores' => $eGestor
                    ? $this->scopeResolver->opcoesVendedores($user, $scope['visaoSupervisor'])
                    : [],
                'visaoSupervisor' => $scope['visaoSupervisor'],
                'visaoVendedor' => $scope['visaoVendedor'],
            ],
            'metaGauge' => $temEscopo && $mostraBlocos
                // `isRepresentante` fica fora do cache de propósito: depende de quem olha,
                // não do escopo. Ver o docblock de DashboardBlocos::metaGauge().
                ? ['isRepresentante' => $role === 'representante'] + $this->blocos->metaGauge($porVendedor, $codVendedores)
                : null,
            'ligacoesStats' => $mostraBlocos ? $this->blocos->ligacoesStats($usuarioIds) : null,
            'observacoesStats' => $mostraBlocos ? $this->blocos->observacoesStats($usuarioIds) : null,
            /*
             * Gestores veem o embed do Power BI no lugar do gráfico Chart.js. As agregações
             * (as queries mais caras da Home no escopo empresa) não rodam pra eles —
             * ninguém leria o resultado. Vendedor/representante continuam com o gráfico
             * local, cujo escopo já é barato.
             *
             * ⚠️ VENDA é a aba padrão do card, e faturamento a segunda. A troca é
             * deliberada: venda (pedido emitido) é o que o vendedor consegue influenciar
             * hoje; faturamento é consequência, e chega depois. Os dois blocos vêm juntos
             * porque o card alterna sem ida ao servidor — cada um custa duas agregações
             * cacheadas, e ir buscar a outra aba no clique tornaria o alternador lento
             * justamente para quem alterna.
             */
            'vendaComparacao' => $temEscopo && $mostraBlocos && ! $eGestor
                ? $this->blocos->vendaComparacao($porVendedor, $codVendedores)
                : null,
            'faturamentoComparacao' => $temEscopo && $mostraBlocos && ! $eGestor
                ? $this->blocos->faturamentoComparacao($porVendedor, $codVendedores)
                : null,
            'biEmbedUrl' => $eGestor ? $this->urlDoBi() : null,
            'carteiraSegmento' => $temEscopo && $mostraBlocos ? $this->blocos->carteiraSegmento($porVendedor, $codVendedores) : null,
            'orcamentosStats' => $mostraBlocos ? $this->blocos->orcamentosStats($porUsuario, $usuarioIds) : null,
            'pedidosAtencao' => $temEscopo && $mostraBlocos ? $this->blocos->pedidosAtencao($porVendedor, $codVendedores) : null,
        ]);
    }

    /**
     * Recusa qualquer coisa que não seja o host oficial: a URL vai direto no `src`
     * de um iframe, então um valor alterado no .env viraria XSS.
     */
    private function urlDoBi(): ?string
    {
        $url = (string) config('powerbi.embed_url');

        return str_starts_with($url, 'https://app.powerbi.com/') ? $url : null;
    }
}
