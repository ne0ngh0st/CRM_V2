<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Services\Receita\BaseAberta;
use App\Services\Receita\PorteEmpresa;
use App\Services\Receita\SituacaoCadastral;
use App\Services\VisaoDiretor\SugestaoDeVinculo;
use App\Services\VisaoDiretor\VinculoPorCliente;

/**
 * Acha o CNPJ de cada rede do mercado (Maiores redes) na base aberta da Receita: a
 * empresa que é DONA das lojas, para a página dizer se a rede está baixada ou inapta.
 *
 * Não é o CNPJ dos nossos clientes da rede (esses já têm situação no `cnpj_situacoes`):
 * a maioria das ~900 redes não tem cliente nosso, e é delas que a diretoria quer saber.
 *
 * Como (protótipo validado em 2026-10-16 contra os vínculos de produção: das redes
 * "confiáveis" com cliente nosso, 80% batem com a empresa dos clientes e quase todo o
 * resto é o vínculo do CRM ligando o grupo inteiro ou um homônimo — ver docs/visao-diretor.md):
 *
 * 1. `lerEmpresas()`: razão social começando com a marca da rede → raízes candidatas.
 * 2. `lerEstabelecimentos()`: estabelecimento com a marca no nome fantasia, OU de uma raiz
 *    da etapa 1 (a Americanas S.A. tem ~830 lojas com o fantasia em branco). Conta, por
 *    rede e raiz, as lojas ATIVAS no ramo da categoria (`CategoriasDeRede::cnaeDaCategoria`).
 * 3. `lerEmpresasDasFinalistas()` e `lerMatrizes()`: razão, natureza, capital e a matriz
 *    das poucas raízes que disputam cada rede.
 * 4. `resultado()`: vence a empresa com mais lojas VIVAS no ramo — empresa incorporada ou
 *    fechada (todas as lojas baixadas) perde para a que está operando.
 *
 * O nome casa pela MESMA regra do vínculo de clientes (`VinculoPorCliente::casa()`): a
 * rede é a "conta", o nome da Receita é o "cliente".
 *
 * Guarda estado entre as etapas: uma instância por carga (`app()->make()`, nunca singleton).
 */
class CnpjDasRedes
{
    public const ALTA = 'alta';

    public const MEDIA = 'media';

    public const SO_FECHADAS = 'so_fechadas';

    public const FRANQUIA = 'franquia';

    public const NAO_IDENTIFICADA = 'nao_identificada';

    public const MANUAL = 'manual';

    /** Rótulo de cada confiança — a tela e o Excel leem daqui. */
    public const CONFIANCAS = [
        self::ALTA => 'Confiável',
        self::MEDIA => 'Provável',
        self::SO_FECHADAS => 'Só achou empresa fechada',
        self::FRANQUIA => 'Franquia (sem CNPJ único)',
        self::NAO_IDENTIFICADA => 'Não identificado',
        self::MANUAL => 'Informado à mão',
    ];

    /** Franqueadora / holding / sede: não vende ao público, mas é a "dona" da marca (desempate). */
    private const CNAES_SEDE = ['7740', '8211', '7020', '6462', '6463', '4619', '4618'];

    /** Empresário individual ("MEI" com o CPF na razão): nome parecido, sem loja viva, é ruído. */
    private const EMPRESARIO_INDIVIDUAL = '2135';

    /** Raízes que seguem para a etapa 3, por rede (mais as empatadas com a última). */
    private const FINALISTAS = 5;

    private const ATIVA = '02';

    /** @var array<int, array{0: string, 1: int}> id => [categoria, lojas da planilha] */
    private array $redes = [];

    /** @var array<string, list<array{0: int, 1: list<string>, 2: list<string>}>> 3 letras => [id, marca, grafia] */
    private array $indice = [];

    /** @var array<string, list<int>> raiz => redes cuja marca está na razão social */
    private array $porRazao = [];

    /**
     * Por rede e raiz: [estabelecimentos, ativos no ramo, com o nome, com o nome e ativos,
     * com o nome, ativos e no ramo].
     *
     * @var array<int, array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}>>
     */
    private array $contagem = [];

    /** @var array<int, int> rede => estabelecimentos ATIVOS com o nome, de qualquer empresa */
    private array $ativosComNome = [];

    /** @var array<int, list<string>> */
    private array $finalistas = [];

    /** @var array<string, true> */
    private array $raizesFinalistas = [];

    /** @var array<string, array{razao: string, natureza: string, capital: ?string, porte: ?string}> */
    private array $empresas = [];

    /** @var array<string, array{cnpj: string, situacao: string, data: ?string, cnae: ?string}> */
    private array $matrizes = [];

    public function __construct(
        private readonly SugestaoDeVinculo $nomes,
        private readonly VinculoPorCliente $vinculo,
    ) {
    }

    /**
     * @param  iterable<array{id: int, nome: string, apelidos: ?array, categoria: string, lojas: ?int}>  $redes
     */
    public function preparar(iterable $redes): void
    {
        foreach ($redes as $r) {
            $this->redes[$r['id']] = [$r['categoria'], (int) ($r['lojas'] ?? 0)];

            foreach ([$r['nome'], ...($r['apelidos'] ?? [])] as $nome) {
                foreach ($this->vinculo->alternativas($nome) as [$marca]) {
                    $grafia = $this->vinculo->grafia($marca);
                    $this->indice[substr(implode('', $grafia), 0, 3)][] = [$r['id'], $marca, $grafia];
                }
            }
        }
    }

    /**
     * As redes cujo nome casa com este nome da Receita (fantasia ou razão, em latin1).
     * Só as redes que começam pelas mesmas 3 letras são comparadas.
     *
     * @return list<int>
     */
    public function redesDoNome(string $latin1): array
    {
        if (trim($latin1) === '') {
            return [];
        }

        $marca = $this->nomes->marca(mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1'));
        if ($marca === []) {
            return [];
        }

        $grafia = $this->vinculo->grafia($marca);
        $chave = substr(implode('', $grafia), 0, 3);
        if (strlen($chave) < 3 || ! isset($this->indice[$chave])) {
            return [];
        }

        $achadas = [];
        foreach ($this->indice[$chave] as [$id, $redeMarca, $redeGrafia]) {
            if (! isset($achadas[$id]) && $this->vinculo->casa($redeMarca, $redeGrafia, $marca, $grafia)) {
                $achadas[$id] = true;
            }
        }

        return array_keys($achadas);
    }

    /** Etapa 1 — um zip de Empresas. @param  iterable<string>  $linhas */
    public function lerEmpresas(iterable $linhas): int
    {
        $n = 0;
        foreach ($linhas as $linha) {
            $n++;
            [$raiz, $razao] = self::campos($linha, 3) + [1 => ''];
            $redes = $this->redesDoNome($razao);
            if ($redes !== []) {
                $this->porRazao[$raiz] = $redes;
            }
        }

        return $n;
    }

    /** Etapa 2 — um zip de Estabelecimentos, depois dos 10 de Empresas. @param  iterable<string>  $linhas */
    public function lerEstabelecimentos(iterable $linhas): int
    {
        $n = 0;
        foreach ($linhas as $linha) {
            $n++;
            $c = self::campos($linha, 13);
            $raiz = $c[0];
            $pelaRazao = $this->porRazao[$raiz] ?? [];
            $peloNome = $this->redesDoNome($c[4] ?? '');

            if ($pelaRazao === [] && $peloNome === []) {
                continue;
            }

            $ativa = ($c[5] ?? '') === self::ATIVA;
            $cnae = $c[11] ?? '';

            foreach (array_unique([...$peloNome, ...$pelaRazao]) as $id) {
                $noRamo = $ativa && CategoriasDeRede::cnaeDaCategoria($this->redes[$id][0], $cnae);
                $s = $this->contagem[$id][$raiz] ?? [0, 0, 0, 0, 0];
                $s[0]++;
                $s[1] += (int) $noRamo;

                if (in_array($id, $peloNome, true)) {
                    $s[2]++;
                    $s[3] += (int) $ativa;
                    $s[4] += (int) $noRamo;
                    $this->ativosComNome[$id] = ($this->ativosComNome[$id] ?? 0) + (int) $ativa;
                }

                $this->contagem[$id][$raiz] = $s;
            }
        }

        return $n;
    }

    /**
     * Fim da etapa 2: separa as poucas raízes que disputam cada rede. As etapas 3 só
     * guardam os dados delas — sem isso seriam ~400 mil empresas na memória.
     */
    public function escolherFinalistas(): void
    {
        foreach ($this->contagem as $id => $porRaiz) {
            $pontos = [];
            foreach ($porRaiz as $raiz => $s) {
                $pontos[(string) $raiz] = [$this->lojasVivas($id, (string) $raiz, $s), $s[3], (int) $this->razaoCasa($id, (string) $raiz)];
            }
            uasort($pontos, fn ($a, $b) => $b <=> $a);

            $corte = array_values($pontos)[min(self::FINALISTAS, count($pontos)) - 1];
            $this->finalistas[$id] = array_map('strval', array_keys(array_filter($pontos, fn ($p) => $p >= $corte)));

            foreach ($this->finalistas[$id] as $raiz) {
                $this->raizesFinalistas[$raiz] = true;
            }
        }
    }

    /** Etapa 3a — um zip de Empresas, de novo: só as finalistas. @param  iterable<string>  $linhas */
    public function lerEmpresasDasFinalistas(iterable $linhas): int
    {
        $n = 0;
        foreach ($linhas as $linha) {
            $n++;
            $raiz = BaseAberta::raiz($linha);
            if (! isset($this->raizesFinalistas[$raiz])) {
                continue;
            }

            $c = str_getcsv(mb_convert_encoding(rtrim($linha, "\r\n"), 'UTF-8', 'ISO-8859-1'), ';', '"', '');
            $capital = trim((string) ($c[4] ?? ''));
            $this->empresas[$raiz] = [
                'razao' => trim((string) ($c[1] ?? '')),
                'natureza' => (string) ($c[2] ?? ''),
                'capital' => $capital === '' ? null : number_format((float) str_replace(['.', ','], ['', '.'], $capital), 2, '.', ''),
                'porte' => PorteEmpresa::deCodigo($c[5] ?? null),
            ];
        }

        return $n;
    }

    /** Etapa 3b — um zip de Estabelecimentos, de novo: a matriz das finalistas. @param  iterable<string>  $linhas */
    public function lerMatrizes(iterable $linhas): int
    {
        $n = 0;
        foreach ($linhas as $linha) {
            $n++;
            $raiz = BaseAberta::raiz($linha);
            if (! isset($this->raizesFinalistas[$raiz])) {
                continue;
            }

            $c = self::campos($linha, 13);
            if (($c[3] ?? '') !== '1') {
                continue;
            }

            $cnae = preg_replace('/\D/', '', (string) ($c[11] ?? ''));
            $this->matrizes[$raiz] = [
                'cnpj' => $raiz.($c[1] ?? '').($c[2] ?? ''),
                'situacao' => SituacaoCadastral::deCodigo($c[5] ?? ''),
                'data' => SituacaoCadastral::data($c[6] ?? null),
                'cnae' => strlen($cnae) === 7 ? $cnae : null,
            ];
        }

        return $n;
    }

    /**
     * A empresa de cada rede e a confiança da escolha.
     *
     * - **Confiável**: 3+ lojas vivas, e a empresa tem 40%+ das lojas ativas com o nome
     *   ou cobre 30%+ das lojas da planilha.
     * - **Franquia**: o nome está em 10+ lojas ativas e nenhuma empresa domina — cada loja
     *   é um franqueado; não existe "o CNPJ da rede".
     * - **Provável**: 2+ lojas vivas, ou a marca na razão social com a matriz ativa.
     * - **Só achou empresa fechada**: nada vivo em lugar nenhum — candidata a rede fechada.
     * - O resto (casamento fraco) não grava CNPJ: **Não identificado**.
     *
     * @return array<int, array{confianca: string, cnpj: ?string, razao_social: ?string, lojas_ativas: ?int,
     *     matriz: ?array{cnpj: string, situacao: string, data: ?string, cnae: ?string}, capital: ?string, porte: ?string}>
     */
    public function resultado(): array
    {
        $saida = [];

        foreach ($this->redes as $id => [, $lojas]) {
            $melhor = null;
            $pontos = null;

            foreach ($this->finalistas[$id] ?? [] as $raiz) {
                $p = $this->pontos($id, $raiz);
                if ($pontos === null || $p > $pontos) {
                    [$melhor, $pontos] = [$raiz, $p];
                }
            }

            $vazio = ['confianca' => self::NAO_IDENTIFICADA, 'cnpj' => null, 'razao_social' => null, 'lojas_ativas' => null, 'matriz' => null, 'capital' => null, 'porte' => null];
            $matriz = $melhor !== null ? ($this->matrizes[$melhor] ?? null) : null;

            if ($matriz === null) {
                $saida[$id] = $vazio;

                continue;
            }

            $s = $this->contagem[$id][$melhor];
            $vivas = $this->lojasVivas($id, $melhor, $s);
            $ativosComNome = $this->ativosComNome[$id] ?? 0;
            $fatia = $ativosComNome > 0 ? $s[3] / $ativosComNome : 0;
            $cobre = $lojas > 0 ? $vivas / $lojas : 0;
            $matrizAtiva = $matriz['situacao'] === SituacaoCadastral::ATIVA;
            $empresa = $this->empresas[$melhor] ?? null;
            $individual = ($empresa['natureza'] ?? '') === self::EMPRESARIO_INDIVIDUAL;

            $confianca = match (true) {
                $vivas === 0 && $s[3] === 0 && ! $matrizAtiva && ! $individual => self::SO_FECHADAS,
                $vivas >= 3 && ($fatia >= 0.4 || $cobre >= 0.3) => self::ALTA,
                $ativosComNome >= 10 && $fatia < 0.4 && $cobre < 0.3 => self::FRANQUIA,
                $vivas >= 2 || ($this->razaoCasa($id, $melhor) && $matrizAtiva) => self::MEDIA,
                default => self::NAO_IDENTIFICADA,
            };

            $saida[$id] = in_array($confianca, [self::FRANQUIA, self::NAO_IDENTIFICADA], true)
                ? ['confianca' => $confianca] + $vazio
                : [
                    'confianca' => $confianca,
                    'cnpj' => $matriz['cnpj'],
                    'razao_social' => $empresa['razao'] ?? null,
                    'lojas_ativas' => $vivas,
                    'matriz' => $matriz,
                    'capital' => $empresa['capital'] ?? null,
                    'porte' => $empresa['porte'] ?? null,
                ];
        }

        return $saida;
    }

    /** Ordem de preferência entre duas empresas candidatas à mesma rede. */
    private function pontos(int $id, string $raiz): array
    {
        $s = $this->contagem[$id][$raiz];
        $matriz = $this->matrizes[$raiz] ?? null;
        $sede = false;
        foreach (self::CNAES_SEDE as $p) {
            $sede = $sede || str_starts_with((string) ($matriz['cnae'] ?? ''), $p);
        }

        return [
            // Lojas VIVAS no ramo certo primeiro.
            $this->lojasVivas($id, $raiz, $s),
            $s[3],
            (int) $this->razaoCasa($id, $raiz),
            (int) (($matriz['situacao'] ?? null) === SituacaoCadastral::ATIVA),
            $s[2],
            (int) $sede,
            (float) ($this->empresas[$raiz]['capital'] ?? 0),
        ];
    }

    /**
     * Com a marca na razão social, toda loja ativa no ramo conta, com ou sem o nome no
     * fantasia; sem, só as que têm o nome.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $s
     */
    private function lojasVivas(int $id, string $raiz, array $s): int
    {
        return $this->razaoCasa($id, $raiz) ? max($s[1], $s[4]) : $s[4];
    }

    private function razaoCasa(int $id, string $raiz): bool
    {
        return in_array($id, $this->porRazao[$raiz] ?? [], true);
    }

    /**
     * Os campos da linha (`"a";"b";"c"`, sempre entre aspas na base da Receita), em latin1.
     *
     * @return list<string>
     */
    private static function campos(string $linha, int $limite): array
    {
        return explode('";"', substr(rtrim($linha, "\r\n"), 1, -1), $limite);
    }
}
