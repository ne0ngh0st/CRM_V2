<?php

namespace App\Console\Commands;

use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\Segmento;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\ContaDoLead;
use App\Services\VisaoDiretor\SugestaoDeVinculo;
use App\Services\VisaoDiretor\VinculoPorRazaoSocial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contas da aba SUPERMERCADISTA da Maiores por Segmento, a partir do ranking da ABRAS
 * (`database/data/visao-diretor/ranking-abras-2026.json`, extraído do PDF da associação).
 *
 * A aba existe para mostrar as maiores redes do país e onde já atendemos ou não (Tony,
 * 2026-10-08), então ela nasce do ranking do MERCADO, não dos nossos leads: das 200
 * primeiras, ~100 já são clientes e só ~40 estão entre os leads. O mercado pequeno que não
 * está no ranking continua só como lead, em /leads.
 *
 * O arquivo guarda o nome CURTO de cada rede (o que aparece na tela e o que casa com o nome
 * das nossas filiais) ao lado da razão social da ABRAS, e NÃO guarda o faturamento: a
 * diretoria tirou faturamento desta página (18/09) e não quis de volta (08/10).
 *
 * Depois de criar as contas:
 *   1. `VinculoPorRazaoSocial` liga os clientes pela razão social (a da ABRAS e as do campo
 *      `razoes`, para quem a ABRAS publica só a marca) + CNPJ raiz, SUBSTITUINDO as
 *      sugestões que a conta tinha. Conta com vínculo manual nunca é tocada;
 *   2. `ContaDoLead::sugerirParaLeadsSemConta()` sugere a rede dos leads já classificados.
 *
 * ⚠️ NÃO usa a sugestão pelo nome das filiais (`VinculoPorCliente`), e LIMPA a que a conta
 * tinha quando a razão social não casa (Tony, 2026-10-08: "se não achou eles, o vínculo
 * está fraco"). Medido em produção: das 31 redes que só o nome ligava, a maioria era
 * homônimo — "Comercial Reis" × atacadista de embalagens, "Supermercado Vianense" × loja
 * de roupa. Rede sem identidade jurídica no CRM fica como Lead até alguém ligar à mão.
 *
 * ⚠️ Idempotente: a conta é achada pela mesma chave de nome da prospecção
 * (`SugestaoDeVinculo::chaveDeNome`), então rodar de novo — ou com o ranking do ano
 * seguinte — atualiza a posição em vez de duplicar. Nome e observação editados na tela
 * nunca são sobrescritos; UF só é preenchida se estiver vazia.
 *
 * ⚠️ A ORDEM da aba é a posição no ranking. Conta do segmento que não está no ranking
 * (criada pela coluna `rede` da prospecção, ou à mão) vai para depois dele, na ordem em
 * que estava.
 */
class ImportarRankingAbras extends Command
{
    protected $signature = 'diretor:importar-ranking-abras
        {--arquivo=database/data/visao-diretor/ranking-abras-2026.json : JSON com o ranking}
        {--limite=200 : quantas posições do ranking viram conta}
        {--dry-run : faz tudo numa transação e desfaz no fim (mostra o relatório sem gravar)}';

    protected $description = 'Contas da aba Supermercadista (Visão Diretor) a partir do ranking da ABRAS';

    public function handle(SugestaoDeVinculo $nomes, ContaDoLead $contaDoLead, VinculoPorRazaoSocial $porRazao, ClientesDaConta $clientesDaConta): int
    {
        $arquivo = base_path((string) $this->option('arquivo'));
        $limite = max(1, (int) $this->option('limite'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $ranking = collect(json_decode((string) file_get_contents($arquivo), true)['ranking'] ?? [])
            ->sortBy('posicao')
            ->take($limite)
            ->values();

        $segmento = Segmento::where('codigo', Segmento::CODIGO_SUPERMERCADISTA)->first();

        if (! $segmento || $ranking->isEmpty()) {
            $this->error($segmento ? 'Ranking vazio.' : 'Segmento 101 (SUPERMERCADISTA) não existe na tabela segmentos.');

            return self::FAILURE;
        }

        $this->info('Banco alvo: '.DB::connection()->getDatabaseName().($dryRun ? ' (dry-run: nada será gravado)' : ''));

        DB::beginTransaction();

        try {
            $existentes = ContaEstrategica::where('segmento_id', $segmento->id)->orderBy('ordem')->orderBy('id')->get();
            $porChave = $existentes->groupBy(fn (ContaEstrategica $c) => $nomes->chaveDeNome($c->nome));

            $novas = 0;
            $atualizadas = 0;
            $ambiguas = [];
            $doRanking = [];

            foreach ($ranking as $r) {
                $candidatas = collect([$r['nome'], $r['razao_social']])
                    ->flatMap(fn (string $n) => $porChave->get($nomes->chaveDeNome($n), collect()))
                    ->unique('id');

                if ($candidatas->count() > 1) {
                    $ambiguas[] = "#{$r['posicao']} {$r['nome']}: ".$candidatas->pluck('nome')->implode(' · ');

                    continue;
                }

                $conta = $candidatas->first() ?? new ContaEstrategica([
                    'segmento_id' => $segmento->id,
                    'nome' => $r['nome'],
                ]);

                $novas += $conta->exists ? 0 : 1;
                $atualizadas += $conta->exists ? 1 : 0;

                $conta->ordem = (int) $r['posicao'];
                if (blank($conta->uf) && filled($r['uf'])) {
                    $conta->uf = $r['uf'];
                }
                $conta->save();

                $doRanking[$conta->id] = $r;
            }

            // Quem não está no ranking vai para depois dele, sem trocar de ordem entre si.
            $depois = (int) $ranking->max('posicao');
            $ultima = $depois;
            foreach ($existentes as $c) {
                if (! isset($doRanking[$c->id])) {
                    $c->forceFill(['ordem' => ++$depois])->save();
                }
            }

            $this->info("Ranking: {$ranking->count()} redes · novas: {$novas} · já existiam: {$atualizadas} · fora do ranking (vão para o fim): ".($depois - $ultima));

            if ($ambiguas !== []) {
                $this->warn('Casaram com mais de uma conta da aba (não mexidas, resolver na tela):');
                foreach ($ambiguas as $l) {
                    $this->line("  - {$l}");
                }
            }

            $catalogo = $porRazao->catalogo();
            $pelaRazao = 0;
            $codigosPelaRazao = 0;
            $limpas = 0;

            foreach ($doRanking as $id => $r) {
                $conta = ContaEstrategica::with('vinculos')->find($id);

                if ($conta->vinculos->contains('origem', ContaEstrategicaVinculo::ORIGEM_MANUAL)) {
                    continue;
                }

                $codigos = $porRazao->clientes([$r['razao_social'], ...($r['razoes'] ?? [])], $catalogo);

                if ($codigos === []) {
                    // A sugestão que houver veio do nome de marca: sem razão social, sai.
                    if ($conta->vinculos->isNotEmpty()) {
                        $clientesDaConta->sincronizarVinculos($conta, []);
                        $limpas++;
                    }

                    continue;
                }

                $clientesDaConta->sincronizarVinculos($conta, array_map(fn (string $c) => [
                    'tipo' => ContaEstrategicaVinculo::TIPO_CLIENTE,
                    'codigo' => $c,
                    'origem' => ContaEstrategicaVinculo::ORIGEM_SUGESTAO,
                ], $codigos));
                $pelaRazao++;
                $codigosPelaRazao += count($codigos);
            }

            $this->newLine();
            $this->info("Clientes pela razão social + CNPJ raiz: {$pelaRazao} redes · {$codigosPelaRazao} códigos");
            if ($limpas > 0) {
                $this->warn("Sugestões por nome removidas (razão social não casou): {$limpas} redes");
            }

            $leads = $contaDoLead->sugerirParaLeadsSemConta();
            $this->newLine();
            $this->info("Leads sugeridos para uma rede: {$leads['sugeridos']}".($leads['ambiguos'] ? " · ambíguos: {$leads['ambiguos']}" : ''));

            $semVinculo = ContaEstrategica::where('segmento_id', $segmento->id)->whereDoesntHave('vinculos')->orderBy('ordem')->pluck('nome', 'ordem');
            $this->newLine();
            $this->warn($semVinculo->count().' rede(s) sem nenhum cliente nosso (ficam como Lead — vincular na tela se forem clientes):');
            foreach ($semVinculo as $ordem => $nome) {
                $this->line("  #{$ordem} {$nome}");
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry-run: tudo desfeito.');
            } else {
                DB::commit();
                $this->info('Gravado.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
