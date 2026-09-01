<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Traz as observações de carteira e de lead escritas no PALMA legado.
 *
 * ⚠️ SÓ ENTRA O QUE ACHA DONO NO V2, e a taxa varia MUITO por tipo (medido em 2026-09-01
 * sobre as 11.523 do espelho):
 *
 *   - `cliente` (8.413): 100% resolvem, quase todas por `cod_client` + `loja`.
 *   - `lead` (2.915): ~20-30%. Não é defeito deste import — é o legado que perdeu a
 *     própria ligação. Lá o identificador do lead tem TRÊS formatos convivendo (e-mail
 *     puro, `email|cep|telefone` e um SHA1), e o SHA1 é calculado sobre um JSON de oito
 *     campos MUTÁVEIS do lead (nome, razão, fantasia, telefone, endereço, UF, código do
 *     vendedor, dataobs — ver `includes/leads/leads_carregar.php:467`). Qualquer
 *     reimportação de base que mexa em telefone ou vendedor troca a identidade do lead e
 *     desgarra as observações dele: reproduzindo a função original, só 24% dos hashes
 *     ainda casam com o BASE_LEADS de hoje. Não portamos esse esquema (Regra de ouro
 *     nº 1) — aqui a ligação é por id de linha, que não muda.
 *   - `lead_manual` (194): ~10%, via LEADS_MANUAIS → e-mail/CNPJ do lead no v2.
 *   - `comentario_gestor` (1): sem equivalente no v2, fica de fora.
 *
 * ⚠️ Observação sem autor identificável NÃO entra (decisão do Tony, 2026-09-01). São as
 * ~220 cujo `usuario_id` não existe mais na `USUARIOS` do legado. `observacoes.user_id` é
 * NOT NULL, e pendurá-las num usuário de sistema atribuiria a alguém texto que essa
 * pessoa não escreveu.
 *
 * ⚠️ `parent_id` é ignorado porque é NULL nas 11.523 linhas — conferido, não presumido.
 * A decisão de "observação é mão única" no v2 não descarta nenhum dado real.
 *
 * Reexecutável: a chave é `observacoes.legado_id`, então rodar de novo traz só o que
 * entrou no legado depois. Não atualiza o que já veio (mesmo motivo do import de
 * orçamentos: a tela do v2 é dona do registro depois que ele chega aqui).
 */
class ImportObservacoesLegado extends Command
{
    protected $signature = 'legado:import-observacoes
        {--fonte=homolog : homolog ou producao}
        {--dry-run : mostra o que faria, sem escrever nada}';

    protected $description = 'Traz do legado as observações de cliente e de lead que ainda não existem no CRM-V2';

    /** @var array<string, array{id: int, cnpj: ?string}> */
    private array $clientesPorCodLoja = [];

    /** @var array<string, array{id: int, cnpj: ?string}> */
    private array $clientesPorCnpj = [];

    /** @var array<string, array{id: int, cnpj: ?string}> */
    private array $leadsPorEmail = [];

    /** @var array<string, array{id: int, cnpj: ?string}> */
    private array $leadsPorCnpj = [];

    /** @var array<string, int> */
    private array $usuariosPorEmail = [];

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $dryRun = (bool) $this->option('dry-run');
        $pdo = LegadoConexao::pdo($fonte);

        $this->carregarMapasDoV2();
        $emailPorUsuarioLegado = $this->carregarUsuariosDoLegado($pdo);
        $leadsManuais = $this->carregarLeadsManuais($pdo);
        $jaImportadas = DB::table('observacoes')->whereNotNull('legado_id')->pluck('legado_id')->flip();

        $this->line(sprintf(
            'No v2: %d clientes, %d leads com e-mail, %d usuários. Já importadas: %d.',
            count($this->clientesPorCodLoja),
            count($this->leadsPorEmail),
            count($this->usuariosPorEmail),
            $jaImportadas->count()
        ));

        $stmt = $pdo->query('SELECT id, tipo, identificador, observacao, usuario_id, data_criacao, data_atualizacao, fixada, cod_client, loja FROM observacoes ORDER BY id');

        $contagem = ['importadas' => 0, 'ja_existiam' => 0, 'sem_autor' => 0];
        $perdidas = [];
        $buffer = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $legadoId = (int) $row['id'];

            if ($jaImportadas->has($legadoId)) {
                $contagem['ja_existiam']++;

                continue;
            }

            $email = $emailPorUsuarioLegado[$row['usuario_id']] ?? null;
            $userId = $email !== null ? ($this->usuariosPorEmail[$email] ?? null) : null;

            if ($userId === null) {
                $contagem['sem_autor']++;

                continue;
            }

            $alvo = $this->resolverAlvo($row, $leadsManuais);

            if ($alvo === null) {
                $tipo = $row['tipo'];
                $perdidas[$tipo] = ($perdidas[$tipo] ?? 0) + 1;

                continue;
            }

            $criadoEm = $row['data_criacao'];

            $buffer[] = [
                'legado_id' => $legadoId,
                'user_id' => $userId,
                'cliente_id' => $alvo['cliente_id'],
                'lead_id' => $alvo['lead_id'],
                // Mesma convenção do ObservacaoController::store: o CNPJ do alvo quando
                // existe, e o literal SEM_CNPJ quando não — a coluna é NOT NULL.
                'cnpj' => $alvo['cnpj'] ?: 'SEM_CNPJ',
                'mensagem' => trim((string) $row['observacao']),
                'fixada' => (bool) $row['fixada'],
                'created_at' => $criadoEm,
                'updated_at' => $row['data_atualizacao'] ?: $criadoEm,
            ];

            $contagem['importadas']++;

            if (count($buffer) >= 500) {
                if (! $dryRun) {
                    DB::table('observacoes')->insert($buffer);
                }
                $buffer = [];
            }
        }

        if ($buffer !== [] && ! $dryRun) {
            DB::table('observacoes')->insert($buffer);
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Observações importadas: {$contagem['importadas']}");
        $this->line("Já existiam (ligadas pelo legado_id): {$contagem['ja_existiam']}");

        if ($contagem['sem_autor'] > 0) {
            $this->warn("Ignoradas por autor inexistente no v2: {$contagem['sem_autor']}");
        }

        foreach ($perdidas as $tipo => $n) {
            $this->warn("Ignoradas por não achar o {$tipo} correspondente: {$n}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $leadsManuais
     * @return array{cliente_id: ?int, lead_id: ?int, cnpj: ?string}|null
     */
    private function resolverAlvo(array $row, array $leadsManuais): ?array
    {
        $identificador = trim((string) $row['identificador']);

        if ($row['tipo'] === 'cliente') {
            $cliente = $this->clientesPorCodLoja[self::chaveCodLoja($row['cod_client'], $row['loja'])]
                ?? $this->clientesPorCnpj[self::soDigitos($identificador)]
                ?? null;

            return $cliente === null ? null : ['cliente_id' => $cliente['id'], 'lead_id' => null, 'cnpj' => $cliente['cnpj']];
        }

        if ($row['tipo'] === 'lead') {
            // Formatos do legado: 'email', 'email|cep|telefone' e SHA1. Só os dois
            // primeiros dão pra resolver; o hash não sobrevive a reimportação de base.
            $email = mb_strtolower(str_contains($identificador, '|') ? explode('|', $identificador)[0] : $identificador);
            $lead = str_contains($email, '@') ? ($this->leadsPorEmail[$email] ?? null) : null;

            return $lead === null ? null : ['cliente_id' => null, 'lead_id' => $lead['id'], 'cnpj' => $lead['cnpj']];
        }

        if ($row['tipo'] === 'lead_manual') {
            $manual = $leadsManuais[$identificador] ?? null;
            if ($manual === null) {
                return null;
            }

            $email = mb_strtolower(trim((string) ($manual['email'] ?? '')));
            $lead = ($email !== '' ? ($this->leadsPorEmail[$email] ?? null) : null)
                ?? ($this->leadsPorCnpj[self::soDigitos($manual['cnpj'] ?? '')] ?? null);

            return $lead === null ? null : ['cliente_id' => null, 'lead_id' => $lead['id'], 'cnpj' => $lead['cnpj']];
        }

        // 'comentario_gestor' e qualquer tipo novo: sem equivalente no v2.
        return null;
    }

    private function carregarMapasDoV2(): void
    {
        DB::table('clientes')->select('id', 'cod_cliente', 'loja', 'cnpj')
            ->orderBy('id')
            ->cursor()
            ->each(function ($c) {
                $chave = self::chaveCodLoja($c->cod_cliente, $c->loja);
                $registro = ['id' => $c->id, 'cnpj' => $c->cnpj];

                /*
                 * ⚠️ Duas linhas de `clientes` podem cair na mesma chave normalizada: o
                 * TOTVS manda o mesmo cliente ora como '000242|0003', ora '000242|003',
                 * e as duas viram registros distintos aqui (22 casos em 91.293 — mesma
                 * inconsistência de zero-padding que já apareceu em `cod_segmento`).
                 * Desempate estável: fica a de código mais longo, que é a forma
                 * canônica preenchida pelo import. Sem isso, qual das duas recebe a
                 * observação dependeria da ordem de leitura.
                 */
                $atual = $this->clientesPorCodLoja[$chave] ?? null;
                if ($atual === null || strlen((string) $c->cod_cliente) > ($atual['len'] ?? 0)) {
                    $this->clientesPorCodLoja[$chave] = $registro + ['len' => strlen((string) $c->cod_cliente)];
                }

                if ($c->cnpj) {
                    $this->clientesPorCnpj[self::soDigitos($c->cnpj)] ??= $registro;
                }
            });

        DB::table('leads')->select('id', 'email', 'cnpj')
            ->orderBy('id')
            ->cursor()
            ->each(function ($l) {
                $registro = ['id' => $l->id, 'cnpj' => $l->cnpj];

                if ($l->email) {
                    $this->leadsPorEmail[mb_strtolower(trim($l->email))] ??= $registro;
                }
                if ($l->cnpj) {
                    $this->leadsPorCnpj[self::soDigitos($l->cnpj)] ??= $registro;
                }
            });

        $this->usuariosPorEmail = DB::table('users')->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [mb_strtolower(trim($email)) => $id])
            ->all();
    }

    /** @return array<int, string> */
    private function carregarUsuariosDoLegado(PDO $pdo): array
    {
        $mapa = [];
        foreach ($pdo->query('SELECT id, EMAIL FROM USUARIOS') as $u) {
            $mapa[$u['id']] = mb_strtolower(trim((string) $u['EMAIL']));
        }

        return $mapa;
    }

    /** @return array<string, array<string, mixed>> */
    private function carregarLeadsManuais(PDO $pdo): array
    {
        $mapa = [];
        foreach ($pdo->query('SELECT id, email, cnpj FROM LEADS_MANUAIS') as $m) {
            $mapa[(string) $m['id']] = $m;
        }

        return $mapa;
    }

    /**
     * O legado grava o código com largura variável ('5758' e '005758' para o mesmo
     * cliente); o v2 guarda a forma do TOTVS. Zero à esquerda não distingue cliente, então
     * some dos dois lados antes de comparar.
     */
    private static function chaveCodLoja(mixed $codCliente, mixed $loja): string
    {
        return ltrim(trim((string) $codCliente), '0').'|'.ltrim(trim((string) $loja), '0');
    }

    private static function soDigitos(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor);
    }
}
