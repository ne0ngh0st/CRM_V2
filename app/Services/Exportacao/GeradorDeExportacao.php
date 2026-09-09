<?php

namespace App\Services\Exportacao;

use App\Jobs\GerarExportacaoJob;
use App\Models\Exportacao;
use App\Models\User;
use App\Services\Escopo\ModoVisao;
use App\Services\Notificacao\NotificacaoService;
use App\Support\Uploads\Disco;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Registra, gera e entrega toda planilha do sistema.
 *
 * ⚠️ TODA planilha passa por aqui, inclusive as que baixam na hora — é isso que faz a
 * central de downloads existir. Antes só a Carteira registrava; as outras oito eram
 * `Excel::download()` direto no controller e desapareciam no histórico do navegador de
 * quem clicou. Quem fechasse a aba não tinha como reaver o arquivo, e o único ponteiro
 * para a planilha da Carteira era uma notificação no sino, que some ao ser lida.
 *
 * ⚠️ O ARQUIVO É SEMPRE ESCRITO NO DISCO, mesmo no caminho síncrono, e a resposta é um
 * REDIRECT para a rota de download. Streamar direto seria uma linha a menos, mas o
 * arquivo não existiria depois — e "existir depois" é a feature inteira. De quebra, todo
 * download do sistema passa a sair por um ponto só, com uma checagem de dono só.
 */
class GeradorDeExportacao
{
    public function __construct(
        private readonly CatalogoDeExportacoes $catalogo,
        private readonly NotificacaoService $notificacoes,
        private readonly ModoVisao $modo,
    ) {}

    /**
     * Registra o pedido e decide o caminho pelo VOLUME, não pelo recurso.
     *
     * ⚠️ O corte é por linhas medidas, não por "esta tela é pesada": a mesma Carteira que
     * leva 95 s no escopo admin sai em menos de um segundo para um vendedor com 283
     * clientes, e obrigá-lo a esperar o sino seria pior que o problema original. Quem
     * decide é `config('exportacoes.limite_linhas_sincrono')`.
     *
     * Devolve a exportação já pronta (caminho síncrono) ou em `processando` (fila).
     */
    public function pedir(string $recurso, Request $request, User $user): Exportacao
    {
        // Monta o plano ANTES de criar o registro: é aqui que a autorização do recurso
        // roda (403 na hora do clique) e que as linhas são contadas.
        $plano = $this->catalogo->plano($recurso, $request, $user);

        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => $recurso,
            /*
             * Só os filtros da tela: o job reconstrói a query a partir deles, e guardá-los
             * deixa o arquivo auditável depois ("por que este Excel tem 300 linhas e não
             * 90 mil?"). `_token` e `page` ficam de fora por não descreverem recorte.
             */
            'filtros' => $request->except(['_token', 'page']),
            /*
             * ⚠️ O modo do supervisor mora na SESSÃO, e o job não tem sessão. Sem guardá-lo
             * aqui, quem pedisse a planilha em "Minha carteira" receberia a da equipe
             * inteira — ver a migration `exportacoes_ganham_tamanho_e_modo_de_visao`.
             */
            'modo_visao' => $this->modo->atual(),
            'status' => Exportacao::STATUS_PROCESSANDO,
            'linhas' => $plano->linhas,
        ]);

        if ($plano->linhas > (int) config('exportacoes.limite_linhas_sincrono')) {
            GerarExportacaoJob::dispatch($exportacao->id);

            return $exportacao;
        }

        // Reaproveita o plano já montado: remontá-lo aqui repetiria a contagem e a query.
        $this->gerar($exportacao, $plano);

        return $exportacao->refresh();
    }

    /**
     * Escreve o arquivo e fecha o registro como pronto (ou erro).
     *
     * O `$plano` só vem preenchido no caminho síncrono, onde ele já foi montado para
     * contar as linhas. No job ele é remontado a partir dos filtros guardados.
     */
    public function gerar(Exportacao $exportacao, ?PlanoDeExportacao $plano = null): bool
    {
        try {
            // O worker tem seu próprio limite de memória, independente do PHP-FPM; no
            // caminho síncrono o trait ExportaPlanilha já ajustou o do request.
            ini_set('memory_limit', '1024M');

            $plano ??= $this->planoDoRegistro($exportacao);

            $nome = $plano->nomeBase.'-'.now()->format('Y-m-d-His').'.xlsx';
            $caminho = "exports/{$exportacao->id}/{$nome}";

            Excel::store($plano->planilha, $caminho, Disco::nomeExports());

            $exportacao->update([
                'status' => Exportacao::STATUS_PRONTO,
                'caminho' => $caminho,
                'nome_arquivo' => $nome,
                'linhas' => $plano->linhas,
                // Conhecido de graça agora; perguntar depois custaria uma ida ao S3 por
                // linha da listagem.
                'bytes' => Disco::exports()->size($caminho),
                'expira_em' => now()->addDays((int) config('exportacoes.dias_validade')),
            ]);

            return true;
        } catch (Throwable $e) {
            $exportacao->update([
                'status' => Exportacao::STATUS_ERRO,
                'erro' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Falha ao gerar exportação', [
                'exportacao_id' => $exportacao->id,
                'recurso' => $exportacao->recurso,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Avisa o dono de que a planilha ficou pronta (ou falhou).
     *
     * ⚠️ Só o caminho ASSÍNCRONO notifica. No síncrono o arquivo já desceu no navegador —
     * um sino tocando para dizer "está pronto aquilo que você acabou de baixar" seria
     * ruído, e ruído é o que faz as pessoas pararem de olhar o sino.
     */
    public function notificar(Exportacao $exportacao): void
    {
        $user = $exportacao->user;

        if (! $user) {
            return;
        }

        $rotulo = $this->catalogo->rotulo($exportacao->recurso);

        if ($exportacao->status === Exportacao::STATUS_PRONTO) {
            $this->notificacoes->notificar(
                destinatario: $user,
                tipo: 'exportacao_pronta',
                titulo: "Planilha de {$rotulo} pronta",
                mensagem: number_format((int) $exportacao->linhas, 0, ',', '.').' linhas. Disponível por '
                    .config('exportacoes.dias_validade').' dias em Meus downloads.',
                link: route('exportacoes.download', $exportacao->id, false),
                referenciaTipo: 'exportacao',
                referenciaId: $exportacao->id,
            );

            return;
        }

        /*
         * Falhar em silêncio seria pior que falhar: o usuário ficaria esperando um arquivo
         * que nunca vem, sem saber que precisa tentar de novo.
         */
        $this->notificacoes->notificar(
            destinatario: $user,
            tipo: 'exportacao_erro',
            titulo: "Não foi possível gerar a planilha de {$rotulo}",
            mensagem: 'Tente novamente. Se persistir, aplique um filtro para reduzir o volume.',
            link: route('exportacoes.index', absolute: false),
            referenciaTipo: 'exportacao',
            referenciaId: $exportacao->id,
        );
    }

    /**
     * Reconstrói o plano a partir do que foi guardado no registro — o caminho do job.
     *
     * ⚠️ A Request é sintética e o usuário vem do REGISTRO, não de `Auth`: na fila não há
     * ninguém logado, e o escopo por perfil tem que ser o de quem pediu.
     */
    private function planoDoRegistro(Exportacao $exportacao): PlanoDeExportacao
    {
        $user = $exportacao->user;

        $request = Request::create('/exportacao', 'GET', $exportacao->filtros ?? []);
        $request->setUserResolver(fn () => $user);

        $this->restaurarModoVisao($exportacao);

        return $this->catalogo->plano($exportacao->recurso, $request, $user);
    }

    /**
     * Devolve ao container o modo Equipe/Minha carteira que valia no momento do pedido.
     *
     * ⚠️ A sessão é sintética de propósito. `ModoVisao` lê de `Session`, e no worker não
     * existe sessão nenhuma — o valor cairia no default EQUIPE, que está certo para o
     * aquecimento de cache e errado para uma exportação pedida em "Minha carteira".
     * Instanciar aqui, antes de o catálogo resolver os controllers, faz todos os
     * resolvers de escopo enxergarem o modo correto sem que nenhum deles saiba disso.
     */
    private function restaurarModoVisao(Exportacao $exportacao): void
    {
        $sessao = new Store('exportacao', new ArraySessionHandler(60));
        $sessao->put(ModoVisao::CHAVE_SESSAO, $exportacao->modo_visao ?: ModoVisao::EQUIPE);

        app()->instance(ModoVisao::class, new ModoVisao($sessao));
    }
}
