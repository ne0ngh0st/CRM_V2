<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadImportacao;
use App\Services\Receita\SituacaoCadastral;
use App\Services\Totvs\Normalizador;
use App\Services\VisaoDiretor\ContaDoLead;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Importa a base de prospecção: TODOS os CSVs da pasta `Leads/` (um por segmento,
 * gerados pelo time de prospecção no modelo `docs/leads-template.csv`).
 *
 * ⚠️ O LAYOUT É O DO TEMPLATE, com cabeçalho exato (`COLUNAS`). Arquivo fora do padrão
 * para o import inteiro com a lista do que faltou — melhor que importar meia planilha.
 * Linha sem CNPJ, sem razão social ou com segmento desconhecido é recusada e listada
 * com arquivo:linha, para quem gerou corrigir na origem.
 *
 * ⚠️ Até 2026-10-02 a fonte era um arquivo único (`CSV/base_marco - SQL.csv`, com o
 * filtro `MARCAÇÃO PROSPECT = SAI PROSPECT`). Os leads que vieram de lá continuam no CRM;
 * como não estão nos CSVs novos, aparecem só como "sumiram da base" (nunca apagados).
 *
 * ⚠️ `cod_vendedor` em branco OU zerado (`0`, `000000`): o lead NOVO nasce SEM DONO
 * (decisão do Tony, 2026-10-02 — "depois vamos atribuir aos vendedores corretos"). Sem
 * código, `LeadController::scopeQuery` o mostra só a quem vê a empresa inteira (admin e
 * diretor); nenhum vendedor o vê. A ATRIBUIÇÃO é o próprio import: preencher o código no
 * CSV e rodar de novo — o lead é adotado pelo CNPJ e ganha o dono. Campo em branco no
 * CSV nunca apaga o dono que um lead já tem.
 * (Até esta data o lead sem código ia para a Venda Interna, 010617.)
 *
 * Difere do `legado:import-leads` em três pontos, e todos são correção de defeito, não
 * preferência:
 *
 * 1. NÃO APAGA E REINSERE. O comando do legado faz
 *    `delete where origem='sistema'` seguido de insert, o que troca o `id` de TODOS os
 *    leads a cada rodada. Como `observacoes.lead_id` e `agendamentos_ligacoes.lead_id`
 *    são ON DELETE SET NULL, cada importação desgarrava as observações dos seus leads em
 *    silêncio — hoje são 575 apontando para lead. Aqui o lead é adotado pelo CNPJ e o id
 *    é preservado.
 *
 * 2. DEDUPLICA POR CNPJ. A base traz 20.568 linhas marcadas como prospect para apenas
 *    17.154 CNPJs distintos: 3.414 empresas aparecem duas vezes, quase sempre com uma
 *    das cópias mais pobre (razão social em branco). Conferido: das 3.414, só ~62 diferem
 *    em alguma coluna que importa. O comando fica com a linha mais completa. O import
 *    antigo trazia as duas, e o vendedor via a mesma empresa duplicada na tela.
 *
 * 3. NÃO SOBRESCREVE O `status`. Lead que o vendedor marcou como convertido ou excluído
 *    voltava para "ativo" a cada importação, porque o comando antigo recriava tudo com
 *    status fixo. Aqui o status só é definido no cadastro novo.
 *
 * ⚠️ Lead que SUMIU da base não é apagado, de propósito. Apagar anularia as observações
 * que apontam para ele (SET NULL) e o histórico do vendedor sumiria sem deixar rastro.
 * O comando conta e informa quantos são; o que fazer com eles é decisão de negócio.
 *
 * ⚠️ CNPJ QUE NÃO ESTÁ ATIVO NA RECEITA NÃO ENTRA (decisão do Tony, 2026-10-01): inapta,
 * suspensa, baixada, nula ou inexistente. A situação vem de `cnpj_situacoes`, carregada
 * por `receita:importar-situacoes`. Lead NOVO com situação desconhecida também fica de
 * fora — "não pode ter nenhuma" não combina com deixar entrar quem nunca foi conferido —
 * e entra sozinho no import seguinte à carga da Receita. Lead que JÁ ESTÁ no CRM e é
 * conhecido como não ativo vira `excluido` (não é apagado; ver
 * `SituacaoCadastral::sincronizarLeads`), e volta sozinho se o CNPJ for regularizado.
 *
 * ⚠️ CNPJ QUE JÁ É CLIENTE NÃO ENTRA COMO LEAD (decisão do Tony, 2026-10-02): se o CNPJ
 * está em `clientes` (qualquer filial, qualquer vendedor), o vendedor que pegasse o lead
 * estaria prospectando cliente de outro. Compara pelos 14 dígitos (`clientes.cnpj_digitos`,
 * indexada). Lead que JÁ estava no CRM e virou cliente não é mexido — só contado.
 *
 * ⚠️ CNPJ com 12 ou 13 dígitos ganha zero à esquerda: é o Excel comendo o zero de CNPJ
 * gravado como número (mesmo defeito do código de vendedor em 2026-09-17).
 *
 * ⚠️ `origem = manual` e `origem = wordpress` nunca são tocados: um é cadastro do
 * vendedor pela tela, o outro vem do formulário do site. CNPJ que já existe como lead
 * de uma dessas origens é ignorado (não duplica).
 *
 * Lead novo nasce `origem = prospeccao` ("Prospecção" na tela). Lead da base antiga
 * (`sistema`) que reaparece num CSV é adotado pelo CNPJ e passa a ser `prospeccao`.
 *
 * Colunas `rede` e `filiais_rede` (opcionais): ligam o lead a uma conta-alvo da Visão
 * Diretor → Maiores por Segmento — ver `ContaDoLead`.
 */
class ImportLeadsTotvs extends Command
{
    /** Cabeçalho do template, na ordem. Mudou aqui, muda `docs/leads-template.csv`. */
    public const COLUNAS = [
        'cnpj', 'razao_social', 'nome_fantasia', 'segmento', 'cod_vendedor',
        'telefone', 'email', 'endereco', 'cidade', 'uf', 'valor_estimado',
        'rede', 'filiais_rede',
    ];

    /**
     * Campos do registro que NÃO são colunas de `leads`: viajam junto do lead até a
     * ligação com a conta-alvo (`ContaDoLead`) e são tirados antes de gravar.
     */
    private const SO_PARA_CONTA = ['_rede', '_filiais_rede', '_codigo_segmento'];

    protected $signature = 'totvs:import-leads
        {--chunk=1000 : tamanho do lote}
        {--dry-run : lê e conta, sem escrever nada}
        {--rodada= : id em leads_importacoes já criado pela /atualizacoes (uso interno)}';

    protected $description = 'Importa os CSVs de prospecção da pasta Leads/, deduplicando por CNPJ e preservando os ids';

    /**
     * Toda rodada deixa um relatório em `leads_importacoes` — inclusive a simulação e a
     * que falhou (cabeçalho fora do template, por exemplo). É o que a `/atualizacoes` mostra.
     */
    public function handle(): int
    {
        // `--rodada` é a linha que o botão da /atualizacoes já criou (`executando`) no
        // clique. Pelo terminal, a linha nasce aqui.
        $registro = ($id = $this->option('rodada')) !== null
            ? LeadImportacao::query()->find((int) $id)
            : null;

        $registro ??= LeadImportacao::query()->create([
            'simulacao' => (bool) $this->option('dry-run'),
            'status' => 'executando',
            'iniciada_em' => now(),
        ]);

        try {
            $codigo = $this->importar($registro);
        } catch (Throwable $e) {
            $registro->update(['status' => 'falhou', 'erro' => mb_substr($e->getMessage(), 0, 2000), 'concluida_em' => now()]);

            throw $e;
        }

        $registro->update([
            'status' => $codigo === self::SUCCESS ? 'sucesso' : 'falhou',
            'concluida_em' => now(),
        ]);

        return $codigo;
    }

    private function importar(LeadImportacao $registro): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $arquivos = Relatorios::todos('leads');
        if ($arquivos === []) {
            $registro->update(['status' => 'falhou', 'erro' => 'Nenhum CSV na pasta Leads/.']);
            $this->error('Nenhum CSV de leads encontrado (padrões em config/totvs.php, domínio "leads").');

            return self::FAILURE;
        }

        $segmentos = $this->segmentosConhecidos();
        $porCnpj = [];
        $preenchimento = [];
        $recusadas = [];
        $lidas = 0;
        $porArquivo = [];

        foreach ($arquivos as $arquivo) {
            $leitor = Relatorios::abrirArquivo($arquivo, 'leads');
            $porArquivo[] = ['nome' => basename($arquivo), 'linhas' => 0];
            $leitor->exigirColunas(self::COLUNAS);
            $n = $this->lerArquivo($leitor, basename($arquivo), $segmentos, $porCnpj, $preenchimento, $recusadas);
            $porArquivo[array_key_last($porArquivo)]['linhas'] = $n;
            $lidas += $n;
        }

        $registro->update([
            'arquivos' => $porArquivo,
            'recusadas' => array_slice($recusadas, 0, LeadImportacao::MAXIMO_RECUSADAS),
        ]);

        $this->line(sprintf(
            '%d arquivo(s) em Leads/: %s linhas, %s CNPJs distintos.',
            count($arquivos),
            number_format($lidas, 0, ',', '.'),
            number_format(count($porCnpj), 0, ',', '.')
        ));

        if ($recusadas !== []) {
            $this->warn('  linhas recusadas: '.number_format(count($recusadas), 0, ',', '.'));
            foreach (array_slice($recusadas, 0, 20) as $motivo) {
                $this->line('    '.$motivo);
            }
            if (count($recusadas) > 20) {
                $this->line('    … e mais '.(count($recusadas) - 20).'.');
            }
        }

        // Adota pelo CNPJ qualquer lead das bases importadas — inclusive os da base antiga
        // (`sistema`), que passam a ser da prospecção quando aparecem nos CSVs novos.
        $existentes = $this->leadsPorCnpj(fn ($q) => $q->whereIn('origem', Lead::ORIGENS_IMPORTADAS));

        // O mesmo CNPJ já cadastrado à mão ou vindo do site: criar outro duplicaria a
        // empresa na tela, e mexer nele passaria por cima de quem cadastrou.
        $deOutraOrigem = $this->leadsPorCnpj(fn ($q) => $q->whereNotIn('origem', Lead::ORIGENS_IMPORTADAS)->where('status', '!=', 'excluido'));

        $situacao = app(SituacaoCadastral::class);

        $novos = array_diff_key($porCnpj, $existentes);
        $adotados = array_intersect_key($porCnpj, $existentes);
        $sumiram = count(array_diff_key($existentes, $porCnpj));

        $listaOutraOrigem = array_keys(array_intersect_key($novos, $deOutraOrigem));
        $novosDeOutraOrigem = count($listaOutraOrigem);
        $novos = array_diff_key($novos, $deOutraOrigem);

        $jaClientes = $this->cnpjsDeClientes(array_keys($porCnpj));
        $listaJaClientes = array_keys(array_intersect_key($novos, $jaClientes));
        $listaViraramCliente = array_keys(array_intersect_key($adotados, $jaClientes));
        $novosJaClientes = count($listaJaClientes);
        $adotadosJaClientes = count($listaViraramCliente);
        $novos = array_diff_key($novos, $jaClientes);
        // Lead que virou cliente fica como está: nem atualizado pelo CSV, nem ligado a uma
        // rede da Visão Diretor. Quem cuida dele agora é a Carteira.
        $adotados = array_diff_key($adotados, $jaClientes);

        /*
         * CNPJ que a base mensal da Receita ainda não conhece é consultado NA HORA, pelas
         * APIs do cartão CNPJ — senão o lead novo esperaria até a próxima carga mensal.
         * ⚠️ Roda também na simulação: grava só a situação (`cnpj_situacoes`, referência),
         * nunca lead. Sem isso a simulação mostraria "esperando" o que o import resolveria.
         */
        $cnpjsNovos = array_map('strval', array_keys($novos));
        $desconhecidos = array_values(array_diff($cnpjsNovos, array_keys($situacao->situacoes($cnpjsNovos))));
        $naHora = ['consultados' => 0, 'indisponiveis' => 0, 'restantes' => 0];

        if ($desconhecidos !== []) {
            $this->line('  consultando na Receita (cartão CNPJ) '.count($desconhecidos).' CNPJ(s) que a base mensal não conhece…');
            $naHora = $situacao->consultarDesconhecidos($desconhecidos);
        }

        $sitAgora = $situacao->situacoes($cnpjsNovos);
        [$novos, $barrados, $semSituacao] = $this->filtrarPelaReceita($novos, $sitAgora);

        $this->line('  já no CRM (atualiza, mantendo o id): '.number_format(count($adotados), 0, ',', '.'));
        $this->line('  cadastros novos: '.number_format(count($novos), 0, ',', '.'));

        if ($novosJaClientes > 0) {
            $this->line('  barrados (CNPJ já é cliente na carteira): '.number_format($novosJaClientes, 0, ',', '.'));
        }

        if ($novosDeOutraOrigem > 0) {
            $this->line('  ignorados (já existe como lead manual ou do site): '.number_format($novosDeOutraOrigem, 0, ',', '.'));
        }

        if ($adotadosJaClientes > 0) {
            $this->warn('  leads já no CRM cujo CNPJ hoje é cliente (não mexidos): '.number_format($adotadosJaClientes, 0, ',', '.'));
        }

        if ($barrados !== []) {
            $this->line('  barrados (CNPJ não ativo na Receita): '.number_format(array_sum($barrados), 0, ',', '.')
                .' — '.collect($barrados)->map(fn ($n, $s) => "{$s} {$n}")->implode(', '));
        }

        if ($semSituacao > 0) {
            $this->warn('  segurados (Receita não respondeu ou passou do limite por rodada): '.number_format($semSituacao, 0, ',', '.'));
            $this->line('    → importe de novo mais tarde: eles são consultados de novo na próxima rodada.');
        }

        if ($sumiram > 0) {
            $this->warn('  sumiram da base e NÃO serão apagados: '.number_format($sumiram, 0, ',', '.'));
            $this->line('    → apagar anularia as observações que apontam para eles.');
        }

        $sincronia = ['excluidos' => [], 'reativados' => 0];

        if ($dryRun) {
            // Lead novo ainda não tem id: um negativo por CNPJ basta para contar as ligações.
            $ids = $existentes;
            $provisorio = 0;
            foreach (array_keys($novos) as $cnpj) {
                $ids[$cnpj] = --$provisorio;
            }
            $contas = app(ContaDoLead::class)->vincularDaProspeccao($this->itensParaConta($novos + $adotados, $ids), true);
            $this->relatarContas($contas);

            $this->info('[dry-run] nada foi escrito.');
        } else {
            DB::transaction(function () use ($novos, $adotados, $existentes, $chunk) {
                $this->inserir($novos, $chunk);
                $this->atualizar($adotados, $existentes);
            });

            $this->info('Leads gravados: '.number_format(count($novos) + count($adotados), 0, ',', '.'));

            $ids = $this->leadsPorCnpj(fn ($q) => $q->whereIn('origem', Lead::ORIGENS_IMPORTADAS));
            $contas = app(ContaDoLead::class)->vincularDaProspeccao($this->itensParaConta($novos + $adotados, $ids));
            $this->relatarContas($contas);

            $sincronia = $situacao->sincronizarLeads();
            if ($sincronia['excluidos'] !== []) {
                $this->warn('  já no CRM e tirados agora (CNPJ não ativo): '.number_format(array_sum($sincronia['excluidos']), 0, ',', '.'));
            }
            if ($sincronia['reativados'] > 0) {
                $this->line('  voltaram (CNPJ regularizado na Receita): '.number_format($sincronia['reativados'], 0, ',', '.'));
            }
        }

        /*
         * ⚠️ Os nomes destas chaves são o contrato com `Atualizacoes/Index.vue`. Mudou um,
         * mude lá — senão o card mostra zero sem erro nenhum.
         */
        // As listas por trás de cada número do card (o clique abre). Ver LeadImportacao::DETALHES.
        $idParaCnpj = array_flip(array_map('intval', $ids));
        $registro->update(['detalhes' => $this->montarDetalhes($porCnpj, [
            'novos' => array_keys($novos),
            'atualizados' => array_keys($adotados),
            'jaClientes' => $listaJaClientes,
            'naoAtivos' => array_values(array_filter($cnpjsNovos, fn ($c) => isset($sitAgora[$c]) && ! SituacaoCadastral::permiteLead($sitAgora[$c]))),
            'segurados' => array_values(array_filter($cnpjsNovos, fn ($c) => ! isset($sitAgora[$c]))),
            'deOutraOrigem' => $listaOutraOrigem,
            'viraramCliente' => $listaViraramCliente,
        ], $jaClientes, $sitAgora, $contas['detalhe'] ?? [], $idParaCnpj)]);
        unset($contas['detalhe']);

        $registro->update(['resultado' => [
            'linhas' => $lidas,
            'cnpjs' => count($porCnpj),
            'recusadas' => count($recusadas),
            'novos' => count($novos),
            'atualizados' => count($adotados),
            'jaClientes' => $novosJaClientes,
            'leadsQueViraramCliente' => $adotadosJaClientes,
            'deOutraOrigem' => $novosDeOutraOrigem,
            'receitaPorSituacao' => $barrados,
            'segurados' => $semSituacao,
            'consultadosNaHora' => $naHora['consultados'],
            'receitaIndisponivel' => $naHora['indisponiveis'],
            'sumiram' => $sumiram,
            'tiradosPelaReceita' => array_sum($sincronia['excluidos']),
            'voltaram' => $sincronia['reativados'],
            'contas' => $contas,
        ]]);

        return self::SUCCESS;
    }

    /**
     * CNPJ (14 dígitos) → id do lead, para os leads que o filtro devolver. Primeiro id
     * vence: os 13 CNPJs duplicados que o import antigo criou ficam apontando para a
     * linha mais antiga, que é a que as observações provavelmente referenciam.
     *
     * @param  callable(\Illuminate\Database\Query\Builder): mixed  $filtro
     * @return array<string, int>
     */
    private function leadsPorCnpj(callable $filtro): array
    {
        $query = DB::table('leads')->whereNotNull('cnpj')->select('id', 'cnpj')->orderBy('id');
        $filtro($query);

        return $query->cursor()->reduce(function (array $mapa, $l) {
            $mapa[preg_replace('/\D/', '', (string) $l->cnpj)] ??= $l->id;

            return $mapa;
        }, []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $registros
     * @param  array<string, int>  $ids
     * @return list<array{lead_id: int, segmento: ?string, rede: ?string, filiais: ?int, uf: ?string, nome: string, nomes: list<string>}>
     */
    private function itensParaConta(array $registros, array $ids): array
    {
        $itens = [];

        foreach ($registros as $cnpj => $r) {
            if (! isset($ids[$cnpj])) {
                continue; // barrado pela Receita ou por já ser cliente
            }

            $itens[] = [
                'lead_id' => $ids[$cnpj],
                'segmento' => $r['_codigo_segmento'],
                'rede' => $r['_rede'],
                'filiais' => $r['_filiais_rede'],
                'uf' => $r['estado'],
                'nome' => $r['razao_social'],
                'nomes' => array_values(array_filter([$r['razao_social'], $r['nome_fantasia']])),
            ];
        }

        return $itens;
    }

    /** @param  array{criadas: int, confirmados: int, sugeridos: int, ambiguos: int, foraDasAbas: int}  $s */
    private function relatarContas(array $s): void
    {
        $this->line('  Maiores por Segmento:');
        $this->line("    contas novas criadas pela coluna `rede`: {$s['criadas']}");
        $this->line("    leads ligados a uma conta: {$s['confirmados']}");
        $this->line("    sugestões para confirmar na Visão Diretor: {$s['sugeridos']}");

        if ($s['ambiguos'] > 0) {
            $this->warn("    sem sugestão (o nome casa com mais de uma conta): {$s['ambiguos']}");
        }
        if ($s['foraDasAbas'] > 0) {
            $this->warn("    `rede` preenchida fora dos 6 segmentos da Visão Diretor (ignorada): {$s['foraDasAbas']}");
        }
    }

    /**
     * Lê um CSV do template para `$porCnpj`. Devolve quantas linhas leu.
     *
     * @param  array<string, string>  $segmentos  chave normalizada (código ou nome) => nome oficial
     * @param  array<string, array<string, mixed>>  $porCnpj
     * @param  array<string, int>  $preenchimento
     * @param  list<string>  $recusadas
     */
    private function lerArquivo(
        \App\Services\Totvs\LeitorRelatorio $leitor,
        string $nomeArquivo,
        array $segmentos,
        array &$porCnpj,
        array &$preenchimento,
        array &$recusadas,
    ): int {
        $lidas = 0;

        foreach ($leitor->linhas() as $linha) {
            $lidas++;
            // Linha 1 é o cabeçalho: é o número que o Excel mostra para quem corrige.
            $onde = "{$nomeArquivo}:".($lidas + 1);

            if (implode('', array_map('trim', $linha)) === '') {
                continue; // linha em branco no fim da planilha
            }

            $digitos = Normalizador::digitosCnpj($linha['cnpj']);
            if (strlen($digitos) !== 14) {
                $recusadas[] = "{$onde} — CNPJ inválido ('{$linha['cnpj']}')";

                continue;
            }
            // Dígito verificador errado é CNPJ digitado errado: as fontes da Receita recusam,
            // e sem barrar aqui o lead ficava "esperando a Receita" para sempre.
            if (! Normalizador::cnpjValido($digitos)) {
                $certo = Normalizador::dvCnpj(substr($digitos, 0, 12));
                // Dígitos todos iguais (000…, 111…) não têm "final certo" a sugerir.
                $recusadas[] = preg_match('/^(\d)\1{13}$/', $digitos)
                    ? "{$onde} — CNPJ inválido ('{$linha['cnpj']}')"
                    : "{$onde} — CNPJ com dígito verificador errado ('{$linha['cnpj']}'; "
                        ."com esta base, o certo terminaria em -{$certo}). Conferir o CNPJ na planilha";

                continue;
            }

            $razaoSocial = Normalizador::valorOuNull($linha['razao_social']);
            if ($razaoSocial === null) {
                $recusadas[] = "{$onde} — sem razão social";

                continue;
            }

            $segmento = $segmentos[$this->chaveSegmento($linha['segmento'])] ?? null;
            if ($segmento === null) {
                $recusadas[] = "{$onde} — segmento desconhecido ('{$linha['segmento']}')";

                continue;
            }

            $fantasia = Normalizador::valorOuNull($linha['nome_fantasia']);

            $registro = [
                'cod_vendedor' => $this->codigoVendedorOuNull($linha['cod_vendedor']),
                'nome' => $fantasia ?? $razaoSocial,
                'razao_social' => $razaoSocial,
                'nome_fantasia' => $fantasia,
                'cnpj' => Normalizador::documento($digitos),
                'email' => Normalizador::email($linha['email']),
                'telefone' => Normalizador::valorOuNull($linha['telefone']),
                'endereco' => Normalizador::valorOuNull($linha['endereco']),
                'cidade' => Normalizador::valorOuNull($linha['cidade']),
                'estado' => Normalizador::uf($linha['uf']),
                'segmento' => $segmento['nome'],
                'valor_estimado' => $this->valorPositivoOuNull($linha['valor_estimado']),
                '_codigo_segmento' => $segmento['codigo'],
                '_rede' => Normalizador::valorOuNull($linha['rede']),
                '_filiais_rede' => ($f = (int) Normalizador::numero($linha['filiais_rede'])) > 0 ? $f : null,
            ];

            // Mesmo CNPJ em duas linhas (ou dois arquivos): fica a mais completa.
            $preenchidos = count(array_filter($registro, fn ($v) => $v !== null && $v !== ''));

            if (! isset($porCnpj[$digitos]) || $preenchidos > $preenchimento[$digitos]) {
                $porCnpj[$digitos] = $registro;
                $preenchimento[$digitos] = $preenchidos;
            }
        }

        return $lidas;
    }

    /**
     * Segmento aceito pelo código do TOTVS (101) ou pelo nome (SUPERMERCADISTA), sem
     * diferença de caixa ou acento. Grava sempre o nome oficial de `segmentos`, para o
     * filtro da tela de Leads não ganhar "Supermercadista" e "SUPERMERCADISTA" separados.
     *
     * O código vai junto porque é por ele que a Visão Diretor sabe em que aba o lead cai.
     *
     * @return array<string, array{codigo: string, nome: string}>
     */
    private function segmentosConhecidos(): array
    {
        $mapa = [];

        foreach (DB::table('segmentos')->get(['codigo', 'nome']) as $s) {
            $segmento = ['codigo' => (string) $s->codigo, 'nome' => $s->nome];
            $mapa[$this->chaveSegmento($s->codigo)] = $segmento;
            $mapa[$this->chaveSegmento($s->nome)] = $segmento;
        }

        return $mapa;
    }

    private function chaveSegmento(mixed $valor): string
    {
        $valor = strtoupper(\Illuminate\Support\Str::ascii(trim((string) $valor)));

        return ctype_digit($valor) ? (string) (int) $valor : preg_replace('/\s+/', ' ', $valor);
    }

    /**
     * CNPJs (14 dígitos) da lista que já existem em `clientes`, com quem é o cliente e de
     * qual vendedor — é o que o card mostra ao clicar em "Já eram clientes".
     *
     * @param  list<int|string>  $cnpjs
     * @return array<string, array{razao: string, codVendedor: ?string}>
     */
    private function cnpjsDeClientes(array $cnpjs): array
    {
        $achados = [];

        foreach (array_chunk(array_map('strval', $cnpjs), 1000) as $lote) {
            DB::table('clientes')->whereIn('cnpj_digitos', $lote)
                ->orderBy('id')->get(['cnpj_digitos', 'razao_social', 'cod_vendedor'])
                ->each(function ($c) use (&$achados) {
                    $achados[(string) $c->cnpj_digitos] ??= ['razao' => $c->razao_social, 'codVendedor' => $c->cod_vendedor];
                });
        }

        return $achados;
    }

    /**
     * Uma lista por número do card: CNPJ formatado, nome e o porquê. Cada lista é cortada
     * em `LeadImportacao::MAXIMO_POR_DETALHE` — a contagem de `resultado` é sempre a real.
     *
     * @param  array<string, array<string, mixed>>  $porCnpj
     * @param  array<string, list<int|string>>  $listas  chave do detalhe → CNPJs (14 dígitos)
     * @param  array<string, array{razao: string, codVendedor: ?string}>  $clientes
     * @param  array<string, string>  $situacoes
     * @param  array{redesCriadas?: list<string>, ligados?: array<int, string>}  $contas
     * @param  array<int, int|string>  $idParaCnpj
     * @return array<string, list<array{cnpj: ?string, nome: string, info: ?string}>>
     */
    private function montarDetalhes(array $porCnpj, array $listas, array $clientes, array $situacoes, array $contas, array $idParaCnpj): array
    {
        $vendedores = app(\App\Services\Vendedores\NomeVendedorResolver::class)->porCodigo(
            collect($clientes)->pluck('codVendedor')->filter()->unique()
        );
        $rotulosReceita = ['BAIXADA' => 'Baixada', 'INAPTA' => 'Inapta', 'SUSPENSA' => 'Suspensa', 'NULA' => 'Nula', SituacaoCadastral::INEXISTENTE => 'Inexistente'];

        $item = fn ($cnpj, ?string $info = null) => [
            'cnpj' => $porCnpj[$cnpj]['cnpj'] ?? Normalizador::documento((string) $cnpj),
            'nome' => $porCnpj[$cnpj]['razao_social'] ?? '—',
            'info' => $info,
        ];

        $detalhes = [];
        foreach ($listas as $chave => $cnpjs) {
            $detalhes[$chave] = collect($cnpjs)
                ->take(LeadImportacao::MAXIMO_POR_DETALHE)
                ->map(fn ($c) => match ($chave) {
                    'jaClientes', 'viraramCliente' => $item($c, isset($clientes[(string) $c])
                        ? 'Cliente: '.$clientes[(string) $c]['razao'].' · vendedor '
                            .($vendedores[$clientes[(string) $c]['codVendedor']] ?? $clientes[(string) $c]['codVendedor'] ?? '—')
                        : null),
                    'naoAtivos' => $item($c, 'Receita: '.($rotulosReceita[$situacoes[(string) $c] ?? ''] ?? ($situacoes[(string) $c] ?? '?'))),
                    'segurados' => $item($c, 'A Receita não respondeu; consultado de novo na próxima rodada'),
                    'deOutraOrigem' => $item($c, 'Já existe como lead cadastrado à mão ou vindo do site'),
                    default => $item($c, ($porCnpj[$c]['segmento'] ?? null)),
                })
                ->values()->all();
        }

        $detalhes['redesCriadas'] = collect($contas['redesCriadas'] ?? [])
            ->map(fn (string $nome) => ['cnpj' => null, 'nome' => $nome, 'info' => 'Rede nova na Maiores por Segmento'])
            ->values()->all();

        $detalhes['ligados'] = collect($contas['ligados'] ?? [])
            ->map(fn (string $rede, $leadId) => $item($idParaCnpj[$leadId] ?? null, 'Rede: '.$rede))
            ->values()->all();

        return $detalhes;
    }

    /**
     * Separa os leads novos que podem entrar dos barrados pela situação na Receita.
     *
     * @param  array<string, array<string, mixed>>  $novos
     * @param  array<string, string>  $conhecidas  cnpj => situação
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, int>, 2: int}
     */
    private function filtrarPelaReceita(array $novos, array $conhecidas): array
    {
        $liberados = [];
        $barrados = [];
        $semSituacao = 0;

        foreach ($novos as $cnpj => $registro) {
            $situacao = $conhecidas[(string) $cnpj] ?? null;

            if ($situacao === null) {
                $semSituacao++;
            } elseif (SituacaoCadastral::permiteLead($situacao)) {
                $liberados[$cnpj] = $registro;
            } else {
                $barrados[$situacao] = ($barrados[$situacao] ?? 0) + 1;
            }
        }

        ksort($barrados);

        return [$liberados, $barrados, $semSituacao];
    }

    /**
     * @param  array<string, array<string, mixed>>  $novos
     */
    private function inserir(array $novos, int $chunk): void
    {
        $agora = now();
        $lote = [];

        foreach ($novos as $registro) {
            $registro = array_diff_key($registro, array_flip(self::SO_PARA_CONTA));
            $lote[] = $registro + [
                'origem' => Lead::ORIGEM_PROSPECCAO,
                'user_id' => null,
                // Só no cadastro NOVO: em lead que já existe, o status é do CRM.
                'status' => 'ativo',
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                DB::table('leads')->insert($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            DB::table('leads')->insert($lote);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $adotados
     * @param  array<string, int>  $existentes
     */
    private function atualizar(array $adotados, array $existentes): void
    {
        $agora = now();

        foreach ($adotados as $digitos => $registro) {
            // Campo em branco no CSV não apaga o que o CRM já tem — em especial o
            // `cod_vendedor`, senão o lead trocaria de dono só por vir sem a coluna.
            $registro = array_filter(array_diff_key($registro, array_flip(self::SO_PARA_CONTA)), fn ($v) => $v !== null);

            // Lead da base antiga que reaparece num CSV de prospecção passa a ser dela.
            DB::table('leads')->where('id', $existentes[$digitos])
                ->update($registro + ['origem' => Lead::ORIGEM_PROSPECCAO, 'updated_at' => $agora]);
        }
    }

    /**
     * Código de vendedor da planilha, com zero vindo da planilha ("0", "000000") tratado
     * como VAZIO: é como quem monta a lista marca "ainda sem dono". Gravar "000000"
     * criaria um dono que não existe e esconderia o lead de todo mundo do mesmo jeito,
     * mas sem aparecer como "sem vendedor".
     */
    private function codigoVendedorOuNull(mixed $valor): ?string
    {
        $codigo = Normalizador::codigoVendedor($valor);

        return $codigo === null || preg_match('/^0+$/', $codigo) ? null : $codigo;
    }

    private function valorPositivoOuNull(mixed $valor): ?float
    {
        $numero = Normalizador::numero($valor);

        return $numero > 0 ? $numero : null;
    }
}
