<?php

/**
 * Relatório pontual: 10 maiores clientes ativos e 10 maiores inativos
 * por faturamento. Não é tela do CRM — gera PDF + texto de e-mail em
 * storage/app/relatorios/.
 *
 * Uso:
 *   docker compose exec app php scripts/gerar-ranking-clientes-pdf.php
 *   docker compose exec app php scripts/gerar-ranking-clientes-pdf.php 010767
 *   docker compose exec app php scripts/gerar-ranking-clientes-pdf.php "caroline silva"
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Carteira\ClienteStatusResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$busca = isset($argv[1]) ? trim((string) $argv[1]) : '';
$vendedor = null;

if ($busca !== '') {
    $vendedor = DB::table('users as u')
        ->leftJoin('vendedor_perfis as vp', 'vp.user_id', '=', 'u.id')
        ->select('u.id', 'u.name', 'u.display_name', 'vp.cod_vendedor')
        ->when(
            preg_match('/^\d+$/', $busca) === 1,
            fn ($q) => $q->where('vp.cod_vendedor', $busca),
            fn ($q) => $q->where(function ($q) use ($busca) {
                $q->where('u.display_name', 'like', '%'.$busca.'%')
                    ->orWhere('u.name', 'like', '%'.$busca.'%');
            })
        )
        ->where('u.is_active', true)
        ->first();

    if (! $vendedor || ! $vendedor->cod_vendedor) {
        fwrite(STDERR, "Vendedor não encontrado ou sem código: {$busca}\n");
        exit(1);
    }
}

$codVendedor = $vendedor->cod_vendedor ?? null;
$nomeVendedor = $vendedor
    ? ($vendedor->display_name ?: $vendedor->name)
    : null;

$hoje = Carbon::now()->startOfDay();
$limiteAtivo = $hoje->copy()->subDays(ClienteStatusResolver::DIAS_ATIVO);
$limiteInativo = $hoje->copy()->subDays(ClienteStatusResolver::DIAS_INATIVANDO);

$filtroCarteira = $codVendedor
    ? "WHERE c.cod_vendedor = ".DB::getPdo()->quote($codVendedor)
        ." AND c.razao_social <> 'DESPESAS DIVERSAS'"
        ." AND IFNULL(c.cnpj, '') NOT LIKE '00.000.000/0000%'"
    : '';
$filtroJoinCarteira = $codVendedor
    ? 'INNER JOIN tmp_carteira tc ON tc.cod_cliente = f.cod_cliente'
    : '';

echo $codVendedor
    ? "Carteira de {$nomeVendedor} ({$codVendedor}). Agregando faturamento...\n"
    : "Empresa inteira. Agregando faturamento (leva ~1,5 min)...\n";

$criarCarteira = $codVendedor ? <<<SQL
DROP TEMPORARY TABLE IF EXISTS tmp_carteira;
CREATE TEMPORARY TABLE tmp_carteira AS
SELECT DISTINCT c.cod_cliente
FROM clientes c
{$filtroCarteira};
ALTER TABLE tmp_carteira ADD PRIMARY KEY (cod_cliente);
SQL : '';

DB::unprepared($criarCarteira.<<<SQL

DROP TEMPORARY TABLE IF EXISTS tmp_vol;
CREATE TEMPORARY TABLE tmp_vol AS
SELECT
  f.cod_cliente,
  SUM(f.valor_total) AS volume,
  SUM(CASE WHEN f.data_emissao >= '2026-01-01' THEN f.valor_total ELSE 0 END) AS volume_2026,
  COUNT(*) AS notas,
  MIN(f.data_emissao) AS primeira,
  MAX(f.data_emissao) AS ultima_nf
FROM faturamentos f
{$filtroJoinCarteira}
WHERE f.cod_cliente IS NOT NULL AND f.cod_cliente <> ''
GROUP BY f.cod_cliente;
ALTER TABLE tmp_vol ADD PRIMARY KEY (cod_cliente);

DROP TEMPORARY TABLE IF EXISTS tmp_emp;
CREATE TEMPORARY TABLE tmp_emp AS
SELECT
  c.cod_cliente,
  MAX(c.data_ultima_compra) AS ultima_compra,
  COUNT(*) AS filiais
FROM clientes c
{$filtroCarteira}
GROUP BY c.cod_cliente;
ALTER TABLE tmp_emp ADD PRIMARY KEY (cod_cliente);

DROP TEMPORARY TABLE IF EXISTS tmp_id;
CREATE TEMPORARY TABLE tmp_id AS
SELECT
  x.cod_cliente,
  x.razao_social,
  x.nome_fantasia,
  x.cnpj,
  x.estado,
  x.cod_segmento,
  x.cod_vendedor,
  x.loja
FROM (
  SELECT
    c.cod_cliente,
    c.razao_social,
    c.nome_fantasia,
    c.cnpj,
    c.estado,
    c.cod_segmento,
    c.cod_vendedor,
    c.loja,
    ROW_NUMBER() OVER (
      PARTITION BY c.cod_cliente
      ORDER BY c.data_ultima_compra DESC, c.loja ASC
    ) AS rn
  FROM clientes c
  {$filtroCarteira}
) x
WHERE x.rn = 1;
ALTER TABLE tmp_id ADD PRIMARY KEY (cod_cliente);

DROP TEMPORARY TABLE IF EXISTS tmp_rank;
CREATE TEMPORARY TABLE tmp_rank AS
SELECT
  CASE
    WHEN v.ultima_nf >= DATE_SUB(CURDATE(), INTERVAL 290 DAY) THEN 'ativo'
    WHEN v.ultima_nf >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) THEN 'inativando'
    ELSE 'inativo'
  END AS status,
  i.cod_cliente,
  i.loja,
  i.razao_social,
  i.nome_fantasia,
  i.cnpj,
  i.estado,
  COALESCE(s.nome, i.cod_segmento) AS segmento,
  i.cod_vendedor,
  e.filiais,
  DATE_FORMAT(e.ultima_compra, '%Y-%m-%d') AS ultima_compra,
  ROUND(v.volume, 2) AS volume,
  ROUND(v.volume_2026, 2) AS volume_2026,
  v.notas,
  DATE_FORMAT(v.primeira, '%Y-%m-%d') AS primeira_nf,
  DATE_FORMAT(v.ultima_nf, '%Y-%m-%d') AS ultima_nf
FROM tmp_emp e
JOIN tmp_vol v ON v.cod_cliente = e.cod_cliente
JOIN tmp_id i ON i.cod_cliente = e.cod_cliente
LEFT JOIN segmentos s ON s.codigo = i.cod_segmento;
SQL);

$linhas = DB::select(<<<'SQL'
SELECT
  pos, status, cod_cliente, loja, razao_social, nome_fantasia, cnpj, estado,
  segmento, cod_vendedor, filiais, ultima_compra, volume, volume_2026, notas,
  primeira_nf, ultima_nf
FROM (
  SELECT
    r.*,
    ROW_NUMBER() OVER (PARTITION BY r.status ORDER BY r.volume DESC) AS pos
  FROM tmp_rank r
  WHERE r.status IN ('ativo', 'inativo')
) x
WHERE pos <= 10
ORDER BY status, pos
SQL);

$emp = DB::selectOne('SELECT COUNT(*) AS empresas, COALESCE(SUM(filiais), 0) AS filiais FROM tmp_emp');
$vol = DB::selectOne('SELECT COALESCE(SUM(notas), 0) AS notas, MIN(primeira) AS desde, MAX(ultima_nf) AS ate, COALESCE(SUM(volume), 0) AS volume_total FROM tmp_vol');
$meta = (object) [
    'empresas' => $emp->empresas,
    'filiais' => $emp->filiais,
    'notas' => $vol->notas,
    'desde' => $vol->desde,
    'ate' => $vol->ate,
    'volume_total' => $vol->volume_total,
];

$ativos = array_values(array_filter($linhas, fn ($r) => $r->status === 'ativo'));
$inativos = array_values(array_filter($linhas, fn ($r) => $r->status === 'inativo'));

$somaAtivos = array_sum(array_map(fn ($r) => (float) $r->volume, $ativos));
$somaInativos = array_sum(array_map(fn ($r) => (float) $r->volume, $inativos));
$volumeTotal = (float) $meta->volume_total;

$porCnpj = [];
foreach (array_merge($ativos, $inativos) as $r) {
    $digitos = preg_replace('/\D/', '', (string) $r->cnpj);
    if ($digitos === '') {
        continue;
    }
    $porCnpj[$digitos][] = $r->cod_cliente;
}
$duplicados = array_filter($porCnpj, fn ($codigos) => count(array_unique($codigos)) > 1);
$observacoes = '';
if ($duplicados !== []) {
    $trechos = [];
    foreach ($duplicados as $codigos) {
        $trechos[] = implode(' e ', array_unique($codigos));
    }
    $observacoes = 'Há CNPJ repetido em mais de um código ('.implode('; ', $trechos).'); os volumes não foram somados — cada código entra na posição que lhe cabe.';
}

$destinoDir = storage_path('app/relatorios');
if (! is_dir($destinoDir)) {
    mkdir($destinoDir, 0755, true);
}

$slug = $codVendedor
    ? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $nomeVendedor), '-'))
    : 'empresa';
if ($slug === '') {
    $slug = $codVendedor ?: 'vendedor';
}

$pdfPath = $destinoDir.'/ranking-clientes-'.$slug.'-2026-09-11.pdf';
$emailPath = $destinoDir.'/ranking-clientes-'.$slug.'-email.txt';

$escopoRotulo = $nomeVendedor
    ? 'Carteira de '.$nomeVendedor
    : 'Empresa inteira';
$pctBaseRotulo = $nomeVendedor
    ? 'do faturamento histórico desta carteira'
    : 'do faturamento histórico da Autopel';
$criterioExtra = $nomeVendedor
    ? 'Somente a carteira de '.$nomeVendedor.' (código '.$codVendedor.'). Volume é o que a empresa comprou da Autopel, não só o faturado no código desta vendedora.'
    : 'Carteira da empresa inteira.';

$pdf = Pdf::loadView('internos.ranking-clientes', [
    'ativos' => $ativos,
    'inativos' => $inativos,
    'somaAtivos' => $somaAtivos,
    'somaInativos' => $somaInativos,
    'pctAtivos' => $volumeTotal > 0 ? ($somaAtivos / $volumeTotal) * 100 : 0,
    'pctInativos' => $volumeTotal > 0 ? ($somaInativos / $volumeTotal) * 100 : 0,
    'volumeTotal' => $volumeTotal,
    'geradoEm' => $hoje->format('d/m/Y'),
    'periodoDe' => $meta->desde ? Carbon::parse($meta->desde)->format('d/m/Y') : '—',
    'periodoAte' => $meta->ate ? Carbon::parse($meta->ate)->format('d/m/Y') : '—',
    'empresas' => number_format((int) $meta->empresas, 0, ',', '.'),
    'filiais' => number_format((int) $meta->filiais, 0, ',', '.'),
    'notas' => (int) $meta->notas,
    'diasAtivo' => ClienteStatusResolver::DIAS_ATIVO,
    'diasInativo' => ClienteStatusResolver::DIAS_INATIVANDO,
    'limiteAtivo' => $limiteAtivo->format('d/m/Y'),
    'limiteInativo' => $limiteInativo->format('d/m/Y'),
    'observacoes' => $observacoes,
    'escopoRotulo' => $escopoRotulo,
    'pctBaseRotulo' => $pctBaseRotulo,
    'criterioExtra' => $criterioExtra,
])->setPaper('a4', 'landscape');

$pdf->save($pdfPath);

$brl = fn (float $v): string => 'R$ '.number_format($v, 2, ',', '.');
$mi = function (float $v): string {
    if (abs($v) >= 1_000_000) {
        return 'R$ '.number_format($v / 1_000_000, 1, ',', '.').' milhões';
    }
    if (abs($v) >= 1_000) {
        return 'R$ '.number_format($v, 2, ',', '.');
    }

    return 'R$ '.number_format($v, 2, ',', '.');
};
$data = fn (?string $v): string => $v ? Carbon::parse($v)->format('d/m/Y') : 'sem registro';

$bloco = function (array $lista) use ($mi, $data): string {
    $linhas = [];
    foreach ($lista as $c) {
        $filiais = (int) $c->filiais;
        $fantasia = ($filiais <= 1 && $c->nome_fantasia && trim($c->nome_fantasia) !== $c->razao_social
            && ! str_contains(mb_strtoupper($c->nome_fantasia), 'INATIVO'))
            ? ' ('.trim($c->nome_fantasia).')'
            : '';
        $filialTxt = $filiais === 1 ? '1 filial' : number_format($filiais, 0, ',', '.').' filiais';
        $linhas[] = sprintf(
            "%d. %s%s\n    Código %s · CNPJ %s · %s · %s\n    Histórico: %s · 2026: %s · Última NF: %s · %s",
            $c->pos,
            $c->razao_social,
            $fantasia,
            $c->cod_cliente,
            $c->cnpj ?: '—',
            $c->estado ?: '—',
            $c->segmento ?: '—',
            $mi((float) $c->volume),
            $mi((float) $c->volume_2026),
            $data($c->ultima_nf),
            $filialTxt
        );
    }

    return implode("\n\n", $linhas);
};

$pctAtivosTxt = $volumeTotal > 0 ? number_format(($somaAtivos / $volumeTotal) * 100, 1, ',', '.') : '0,0';
$pctInativosTxt = $volumeTotal > 0 ? number_format(($somaInativos / $volumeTotal) * 100, 1, ',', '.') : '0,0';
$periodoDe = $meta->desde ? Carbon::parse($meta->desde)->format('d/m/Y') : '—';
$periodoAte = $meta->ate ? Carbon::parse($meta->ate)->format('d/m/Y') : '—';
$obsEmail = $observacoes !== '' ? "\n\nObservação: {$observacoes}" : '';

$introEscopo = $nomeVendedor
    ? "Segue o levantamento da carteira da {$nomeVendedor}: os 10 maiores clientes ativos e os 10 maiores clientes inativos, ordenados do maior para o menor volume de faturamento."
    : 'Segue o levantamento solicitado: os 10 maiores clientes ativos e os 10 maiores clientes inativos, ordenados do maior para o menor volume de faturamento.';
$introCriterio = $nomeVendedor
    ? "O ranking considera só os clientes hoje na carteira dela (código {$codVendedor}). O volume é o faturamento líquido acumulado de {$periodoDe} a {$periodoAte} que essas empresas compraram da Autopel (notas fiscais, já descontadas as devoluções) — não apenas o que saiu no código da vendedora, para a conta antiga da carteira não parecer zerada."
    : "O ranking considera o faturamento líquido acumulado de {$periodoDe} a {$periodoAte} (notas fiscais, já descontadas as devoluções).";

$email = <<<TXT
Boa tarde,

{$introEscopo}

{$introCriterio} Cliente ativo é quem comprou nos últimos 290 dias; inativo, quem está há mais de 365 dias sem nota (ou nunca comprou). A faixa intermediária (“inativando”) ficou de fora das duas listas. Cada linha é uma empresa (código de cliente), com o volume somado.

O PDF com a tabela completa vai em anexo.

10 MAIORES CLIENTES ATIVOS
--------------------------
{$bloco($ativos)}

Soma dos 10: {$brl($somaAtivos)} ({$pctAtivosTxt}% {$pctBaseRotulo}).

10 MAIORES CLIENTES INATIVOS
----------------------------
{$bloco($inativos)}

Soma dos 10: {$brl($somaInativos)} ({$pctInativosTxt}% {$pctBaseRotulo}). São os maiores volumes parados — boa lista para priorizar reativação.{$obsEmail}

Fico à disposição para detalhar qualquer um desses clientes (quebra por filial ou por período).

Atenciosamente,
Antonio Barbosa
Autopel Soluções
TXT;

file_put_contents($emailPath, $email);

$bin = file_get_contents($pdfPath);
$paginas = preg_match_all('/\/Type\s*\/Page[^s]/', $bin);

echo 'PDF  '.$pdfPath.PHP_EOL;
echo 'email '.$emailPath.PHP_EOL;
echo 'bytes '.filesize($pdfPath).PHP_EOL;
echo 'paginas '.$paginas.PHP_EOL;
echo 'ativos '.count($ativos).' | inativos '.count($inativos).PHP_EOL;
echo 'carteira empresas '.$meta->empresas.' | volume '.$volumeTotal.PHP_EOL;
