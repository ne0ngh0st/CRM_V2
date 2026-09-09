<?php

namespace App\Http\Controllers;

use App\Models\Exportacao;
use App\Services\Exportacao\CatalogoDeExportacoes;
use App\Support\Uploads\Disco;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meus downloads — as planilhas que a pessoa pediu, e o download delas.
 *
 * ⚠️ Esta tela existe porque o arquivo tinha UM ponteiro só: a notificação do sino. Ela
 * some quando é lida, e a partir daí não havia caminho nenhum para o arquivo, que ficava
 * mais 7 dias no disco sem ninguém conseguir alcançá-lo. As outras oito exportações nem
 * ponteiro tinham — desciam direto para a pasta de downloads do navegador e acabou.
 */
class ExportacaoController extends Controller
{
    public function __construct(
        private readonly CatalogoDeExportacoes $catalogo,
    ) {}

    public function index(Request $request): Response
    {
        $exportacoes = Exportacao::query()
            ->doUsuario($request->user()->id)
            ->paginate(20)
            ->withQueryString();

        $exportacoes->through(fn (Exportacao $e) => [
            'id' => $e->id,
            // Rótulo pronto do servidor: o front não tem cópia do mapa de recursos.
            'recurso' => $this->catalogo->rotulo($e->recurso),
            'status' => $e->status,
            'linhas' => $e->linhas,
            'bytes' => $e->bytes,
            'erro' => $e->erro,
            'filtros' => $this->filtrosLegiveis($e),
            'criadoEm' => $e->created_at?->format('d/m/Y H:i'),
            'expiraEm' => $e->expira_em?->format('d/m/Y'),
            /*
             * ⚠️ Três estados que o front NÃO deve deduzir de `status`: uma exportação
             * `pronto` pode estar vencida (arquivo já expurgado) e continua sendo `pronto`
             * no banco. Quem sabe a regra é o model.
             */
            'disponivel' => $e->disponivel(),
            'expirou' => $e->expirou(),
            // "Preparando" há mais de uma hora não é espera, é abandono — ver
            // Exportacao::travou(). Sem isto o usuário espera para sempre.
            'travou' => $e->travou(),
            'url' => $e->disponivel() ? route('exportacoes.download', $e->id, false) : null,
        ]);

        return Inertia::render('Exportacoes/Index', [
            'exportacoes' => $exportacoes,
            'diasValidade' => (int) config('exportacoes.dias_validade'),
            /*
             * A tela se recarrega sozinha ENQUANTO houver planilha em preparo, e só nesse
             * caso — mesmo desenho de /atualizacoes. Sem isto, quem chega aqui vindo do
             * aviso "estamos preparando" ficaria olhando um "processando" congelado.
             */
            'emAndamento' => Exportacao::query()
                ->where('user_id', $request->user()->id)
                ->where('status', Exportacao::STATUS_PROCESSANDO)
                ->exists(),
        ]);
    }

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

    /**
     * Os filtros do pedido, em pares rótulo/valor prontos para exibir.
     *
     * ⚠️ Responde "por que este Excel tem 300 linhas e não 90 mil?" — a pergunta que faz
     * alguém desconfiar de um arquivo e regerá-lo à toa. Os nomes de campo se repetem
     * entre telas (`busca`, `estado`, `status`…), então um mapa genérico basta; chave
     * desconhecida aparece como veio, que é melhor que sumir da listagem.
     *
     * @return list<array{rotulo: string, valor: string}>
     */
    private function filtrosLegiveis(Exportacao $exportacao): array
    {
        $rotulos = [
            'busca' => 'Busca',
            'estado' => 'Estado',
            'segmento' => 'Segmento',
            'status' => 'Status',
            'situacao' => 'Situação',
            'aderencia' => 'Aderência',
            'origem' => 'Origem',
            'nivel' => 'Nível',
            'perfil' => 'Perfil',
            'tipo' => 'Tipo',
            'faixa' => 'Faixa',
            'modo' => 'Modo',
            'ano' => 'Ano',
            'mes' => 'Mês',
            'categoria' => 'Categoria',
            'recurso' => 'Aba',
            'ordenar' => 'Ordenação',
            'visao_supervisor' => 'Supervisor',
            'visao_vendedor' => 'Vendedor',
        ];

        /*
         * ⚠️ Ruído de navegação nunca é filtro. `pedir()` já descarta `page`/`_token` na
         * gravação, mas registros criados antes desta rodada os têm guardados — e "Page: 3"
         * numa linha da central parece um recorte de dado que não existe.
         *
         * `ordenar` fica de fora por outro motivo: ele muda a ORDEM das linhas, não quais
         * linhas entram. A coluna existe para responder "por que este arquivo tem 300
         * linhas?", e exibir `Ordenação: nome_asc` aí só acrescenta jargão a uma resposta
         * que era, corretamente, "base completa".
         */
        $ignorar = ['page', '_token', 'aba', 'ordenar'];

        $itens = [];

        foreach ($exportacao->filtros ?? [] as $chave => $valor) {
            if ($valor === null || $valor === '' || is_array($valor) || in_array($chave, $ignorar, true)) {
                continue;
            }

            $itens[] = [
                'rotulo' => $rotulos[$chave] ?? $chave,
                'valor' => (string) $valor,
            ];
        }

        return $itens;
    }
}
