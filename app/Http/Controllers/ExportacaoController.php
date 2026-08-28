<?php

namespace App\Http\Controllers;

use App\Models\Exportacao;
use App\Support\Uploads\Disco;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download das planilhas geradas em segundo plano.
 */
class ExportacaoController extends Controller
{
    public function download(Request $request, Exportacao $exportacao): StreamedResponse
    {
        /*
         * ⚠️ Dono do arquivo, sempre.
         * O id é sequencial e aparece na URL da notificação: sem esta checagem, trocar o
         * número na barra de endereço entregaria a carteira inteira de outra pessoa —
         * exatamente o dado que o escopo por perfil existe para proteger. Nem admin
         * baixa a exportação de outro: se precisar dos dados, gera a própria.
         */
        abort_unless($exportacao->user_id === $request->user()->id, 403);

        abort_unless($exportacao->disponivel(), 404, 'Esta exportação não está mais disponível.');
        abort_unless(Disco::exports()->exists($exportacao->caminho), 404, 'O arquivo foi removido.');

        return Disco::exports()->download($exportacao->caminho, $exportacao->nome_arquivo);
    }
}
