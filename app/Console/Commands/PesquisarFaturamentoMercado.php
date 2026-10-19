<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\ExtratorDeFaturamento;
use App\Services\VisaoDiretor\FaturamentoDeMercado;
use App\Services\VisaoDiretor\PesquisaSupermercadista;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Busca na web o faturamento anual de quem não está no ranking da ABRAS e grava valor,
 * ano e URL. Retomável: quem já foi consultado neste ano (mesmo sem número) não é
 * buscado de novo até o ano seguinte, e um valor da ABRAS nunca é substituído.
 *
 * Fora da requisição e fora do `totvs:atualizar`. A planilha só lê o que já está gravado.
 */
class PesquisarFaturamentoMercado extends Command
{
    protected $signature = 'pesquisa:faturamento-mercado
        {--limite=0 : quantas empresas consultar nesta rodada (0 = todas as que faltam)}';

    protected $description = 'Busca na web o faturamento anual de quem não está no ranking da ABRAS';

    public function handle(PesquisaSupermercadista $pesquisa, ExtratorDeFaturamento $extrator, FaturamentoDeMercado $faturamento): int
    {
        $chave = (string) config('pesquisa.firecrawl_key');
        if ($chave === '') {
            $this->error('FIRECRAWL_API_KEY vazia. Sem ela o comando não busca — e não inventa faturamento.');

            return self::FAILURE;
        }

        set_time_limit(0);

        $limite = (int) $this->option('limite');
        $fila = $pesquisa->pendentesDeFaturamento();
        if ($limite > 0) {
            $fila = array_slice($fila, 0, $limite);
        }

        $this->info(count($fila).' empresa(s) para consultar.');

        $comNumero = 0;
        $semNumero = 0;
        $falhas = 0;

        foreach ($fila as $i => $empresa) {
            $consulta = trim('"'.$empresa['nome'].'" '.($empresa['uf'] ?? '').' faturamento');

            try {
                $resposta = $this->consultar($chave, $consulta);
            } catch (Throwable $e) {
                $falhas++;
                $this->warn($empresa['nome'].': '.$e->getMessage());
                sleep(6);

                continue;
            }

            if (! $resposta->successful()) {
                $falhas++;
                $this->warn($empresa['nome'].': HTTP '.$resposta->status());
                sleep(6);

                continue;
            }

            $dados = $resposta->json('data') ?? [];
            if (isset($dados['web']) && is_array($dados['web'])) {
                $dados = $dados['web'];
            }
            $achado = is_array($dados) ? $extrator->extrair($dados) : null;

            $gravou = $faturamento->gravarWeb(
                $empresa['chave'],
                $achado['valor'] ?? null,
                $achado['ano'] ?? null,
                $achado['url'] ?? null,
                $empresa['contaId'],
            );

            if (! $gravou) {
                continue;
            }

            if ($achado) {
                $comNumero++;
            } else {
                $semNumero++;
            }

            if (($i + 1) % 25 === 0) {
                $this->line(($i + 1).' consultadas · '.$comNumero.' com número');
            }

            sleep(6);
        }

        $this->info("Com número: {$comNumero} · sem número declarado: {$semNumero} · falhas (ficam para a próxima): {$falhas}");

        return self::SUCCESS;
    }

    /**
     * A cota da busca estoura fácil: 10 consultas seguidas já devolveram 429.
     * Espera o que a API pedir e tenta de novo, em vez de pular a empresa.
     */
    private function consultar(string $chave, string $consulta): \Illuminate\Http\Client\Response
    {
        $espera = 15;

        for ($tentativa = 1; $tentativa <= 4; $tentativa++) {
            $resposta = Http::withToken($chave)->timeout(20)->post((string) config('pesquisa.firecrawl_url'), [
                'query' => $consulta,
                'limit' => 5,
                'country' => 'BR',
                'lang' => 'pt',
            ]);

            if ($resposta->status() !== 429 || $tentativa === 4) {
                return $resposta;
            }

            $pedido = (int) $resposta->header('Retry-After');
            sleep($pedido > 0 ? min($pedido, 60) : $espera);
            $espera = min($espera * 2, 60);
        }

        throw new \RuntimeException('consulta sem resposta');
    }
}
