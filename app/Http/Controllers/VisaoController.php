<?php

namespace App\Http\Controllers;

use App\Services\Escopo\ModoVisao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Alternador "Equipe / Minha carteira" do supervisor.
 *
 * Na Autopel o supervisor também vende, e o sistema não modelava isso: o escopo dele
 * resolvia só a equipe. Este alternador troca o escopo em TODAS as telas de uma vez —
 * Painel, Carteira, Leads, Pedidos, Orçamentos, Metas —, porque o modo é lido dentro do
 * `DashboardScopeResolver` e não passado tela a tela.
 *
 * Mesmo desenho da simulação de usuário, pelo mesmo motivo: no legado o equivalente
 * (`supervisor_apenas_proprios`) foi threaded à mão por ~15 arquivos e ficou inconsistente
 * entre telas.
 */
class VisaoController extends Controller
{
    public function alternar(Request $request, ModoVisao $modo): RedirectResponse
    {
        $user = $request->user();

        // Só supervisor tem os dois papéis para alternar. Vendedor já é pessoal por
        // definição; admin e diretor enxergam a empresa e têm os dropdowns de visão.
        abort_unless($user->hasRole('supervisor'), 403);

        // ⚠️ Sem código de vendedor não existe carteira pessoal — o modo pessoal
        // resolveria escopo vazio e a pessoa veria tela em branco achando que quebrou.
        abort_unless($user->vendedorPerfil?->cod_vendedor, 422, 'Seu usuário não tem código de vendedor.');

        $data = $request->validate([
            'modo' => ['required', Rule::in(ModoVisao::MODOS)],
        ]);

        $modo->definir($data['modo']);

        // Volta para onde estava: o alternador vive no cabeçalho e é usado de qualquer
        // tela; mandar sempre para o painel faria o supervisor perder o contexto.
        return back();
    }
}
