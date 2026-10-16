<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Services\VisaoDiretor\VinculoPorCliente;
use Illuminate\Support\Facades\DB;

/**
 * Liga cada rede do mercado (página Maiores redes) aos nossos clientes e leads. É a ÚNICA
 * definição de "clientes desta rede".
 *
 * Grão: `cod_cliente` (o da Carteira agrupada; cada franqueado é um código). Níveis:
 *
 * - `nome`: o nome da rede (ou um apelido) é o começo do fantasia/razão da filial, com as
 *   travas do `VinculoPorCliente`. É o que pega o franqueado (decisão do Tony, 2026-10-16:
 *   franqueado conta como loja da rede).
 * - `grupo`: o grupo do TOTVS casa pelo nome ou pela maioria das filiais; entram todos os
 *   códigos do grupo. 9998 nunca.
 * - `mesma_empresa`: código com a maioria das filiais na mesma raiz de CNPJ de um código já
 *   ligado à rede. A raiz é calculada em memória (Regra de ouro nº 3).
 *
 * ⚠️ CADA CLIENTE PERTENCE A UMA REDE SÓ. Duas redes casando o mesmo código: ganha a de
 * nome mais específico ("SHELL SELECT" ganha de "SHELL"); empate fica fora das duas e vai
 * para `conflitos`. Sem isso o mesmo cliente contaria em duas linhas da página.
 *
 * ⚠️ O dono da rede não entra em nada — decisão do Tony. O JSON já vem sem ele.
 *
 * Lead: mesma regra de nome sobre razão social/fantasia; lead cuja raiz já é cliente fica
 * de fora (a empresa já está na linha do cliente, como no `UniversoDoSegmento`).
 */
class VinculoDeRedes
{
    public const NOME = 'nome';

    public const GRUPO = 'grupo';

    public const MESMA_EMPRESA = 'mesma_empresa';

    public function __construct(private readonly VinculoPorCliente $porCliente)
    {
    }

    /**
     * @param  list<array{nome: string, apelidos?: list<string>, categoria: string}>  $redes
     * @return array{
     *     clientes: array<string, array{rede: int, nivel: string}>,
     *     leads: array<int, array{rede: int, nivel: string}>,
     *     conflitos: list<array{tipo: string, codigo: string, nivel: string, redes: list<int>}>,
     *     ambiguas: list<array{rede: int, tipo: string}>,
     * }
     */
    public function calcular(array $redes): array
    {
        $this->redes = $redes;
        $this->ufDoCodigo = $this->ufPorCodigo();
        $catalogo = $this->porCliente->catalogo();
        $codigosDoGrupo = [];
        foreach ($catalogo['filiais'] as $f) {
            $codigosDoGrupo[$f['grupo']][$f['codigo']] = true;
        }

        $alvos = [];
        $peso = [];
        foreach ($redes as $i => $rede) {
            $alvos[$i] = implode(' / ', array_merge([$rede['nome']], $rede['apelidos'] ?? []));
            $peso[$i] = $this->porCliente->especificidade($alvos[$i]);
        }

        $ambiguas = [];
        $candidatos = [];
        foreach ($redes as $i => $rede) {
            $r = $this->porCliente->sugerir($alvos[$i], CategoriasDeRede::segmento($rede['categoria']), $catalogo, grupoDeOutroSegmento: true);

            if ($r['ambiguo']) {
                $ambiguas[] = ['rede' => $i, 'tipo' => 'cliente'];

                continue;
            }

            foreach ($r['grupos'] as $g) {
                foreach (array_keys($codigosDoGrupo[$g] ?? []) as $codigo) {
                    $candidatos[(string) $codigo][$i] ??= self::GRUPO;
                }
            }
            foreach ($r['clientes'] as $codigo) {
                // Nome casado vale mais que "veio junto com o grupo" na mesma rede.
                $candidatos[(string) $codigo][$i] = self::NOME;
            }
        }

        $conflitos = [];
        $clientes = $this->resolver($candidatos, $peso, 'cliente', $conflitos);

        $empresa = $this->mesmaEmpresa($clientes, $peso, $conflitos);
        $clientes += $empresa;

        $leads = $this->leads($redes, $alvos, $peso, $ambiguas, $conflitos);

        return ['clientes' => $clientes, 'leads' => $leads, 'conflitos' => $conflitos, 'ambiguas' => $ambiguas];
    }

    /** Quem casou o código pelo próprio nome ganha de quem só o trouxe junto com um grupo. */
    private const FORCA = [self::NOME => 3, self::GRUPO => 2, self::MESMA_EMPRESA => 1];

    /** @var list<array> as redes da rodada (uf, lojas), para o desempate */
    private array $redes = [];

    /** @var array<string, string> código → UF da maioria das filiais */
    private array $ufDoCodigo = [];

    /**
     * Um dono por código. Ordem:
     * 1. o nível (o grupo CARREFOUR do TOTVS tem lojas ATACADAO, e elas ficam com a rede
     *    ATACADÃO, que as casou pelo nome);
     * 2. o peso (nome mais específico: "SHELL SELECT" ganha de "SHELL");
     * 3. a UF da sede da rede igual à do cliente ("FARMÁCIAS SÃO PAULO" é do PR, a
     *    "DROGARIA SÃO PAULO" de SP — a marca dos dois é SÃO PAULO). Só para quem veio pelo
     *    nome ou pela empresa;
     * 4. a rede maior (mais lojas no mercado).
     * Os três últimos ficam registrados em `conflitos` com `decidido`, para revisão. Empate
     * em tudo: o código fica fora das redes.
     *
     * @param  array<string, array<int, string>>  $candidatos  código → rede → nível
     * @param  array<int, int>  $peso
     * @return array<string, array{rede: int, nivel: string}>
     */
    private function resolver(array $candidatos, array $peso, string $tipo, array &$conflitos): array
    {
        $saida = [];

        foreach ($candidatos as $codigo => $porRede) {
            $vencedoras = $this->melhores(array_keys($porRede), fn (int $i) => self::FORCA[$porRede[$i]]);
            $empatadas = $vencedoras;
            $criterio = null;

            foreach ([
                'nome mais específico' => fn (int $i) => $peso[$i],
                // Não vale para quem veio pelo GRUPO: o grupo inteiro vai para uma rede só,
                // e decidir por UF código a código o partiria entre as duas.
                'UF da rede' => fn (int $i) => (int) ($porRede[$i] !== self::GRUPO
                    && ($this->redes[$i]['uf'] ?? null) !== null
                    && ($this->redes[$i]['uf'] ?? null) === ($this->ufDoCodigo[$codigo] ?? null)),
                'rede maior' => fn (int $i) => (int) ($this->redes[$i]['lojas'] ?? 0),
            ] as $nome => $regra) {
                if (count($vencedoras) === 1) {
                    break;
                }
                $vencedoras = $this->melhores($vencedoras, $regra);
                $criterio = $nome;
            }

            if (count($vencedoras) > 1) {
                $conflitos[] = ['tipo' => $tipo, 'codigo' => (string) $codigo, 'nivel' => $porRede[$vencedoras[0]], 'redes' => $empatadas, 'decidido' => null];

                continue;
            }

            if ($criterio !== null && $criterio !== 'nome mais específico') {
                $conflitos[] = ['tipo' => $tipo, 'codigo' => (string) $codigo, 'nivel' => $porRede[$vencedoras[0]], 'redes' => $empatadas, 'decidido' => $vencedoras[0], 'criterio' => $criterio];
            }

            $saida[$codigo] = ['rede' => $vencedoras[0], 'nivel' => $porRede[$vencedoras[0]]];
        }

        return $saida;
    }

    /** @return array<string, string> código → UF onde ele tem mais filiais */
    private function ufPorCodigo(): array
    {
        $contagem = [];
        foreach (DB::table('clientes')->whereNotNull('estado')->select('cod_cliente', 'estado')->cursor() as $c) {
            $contagem[(string) $c->cod_cliente][(string) $c->estado] = ($contagem[(string) $c->cod_cliente][(string) $c->estado] ?? 0) + 1;
        }

        $saida = [];
        foreach ($contagem as $codigo => $ufs) {
            arsort($ufs);
            $saida[$codigo] = (string) array_key_first($ufs);
        }

        foreach (DB::table('leads')->whereNotNull('estado')->select('id', 'estado')->cursor() as $l) {
            $saida['L'.$l->id] = (string) $l->estado;
        }

        return $saida;
    }

    /**
     * @param  list<int>  $redes
     * @return list<int> as de maior valor
     */
    private function melhores(array $redes, \Closure $valor): array
    {
        $maior = max(array_map($valor, $redes));

        return array_values(array_filter($redes, fn ($i) => $valor($i) === $maior));
    }

    /**
     * Os outros códigos da mesma empresa (raiz do CNPJ) de quem já está ligado. Só código
     * com a MAIORIA das filiais nas raízes da rede — o mesmo critério do
     * `VinculoPorRazaoSocial`. Nunca tira código já ligado a outra rede.
     *
     * @param  array<string, array{rede: int, nivel: string}>  $ligados
     * @param  array<int, int>  $peso
     * @return array<string, array{rede: int, nivel: string}>
     */
    private function mesmaEmpresa(array $ligados, array $peso, array &$conflitos): array
    {
        $filiais = [];
        $codigosDaRaiz = [];

        foreach (DB::table('clientes')->select('cod_cliente', 'cnpj_digitos')->cursor() as $c) {
            $codigo = (string) $c->cod_cliente;
            $raiz = strlen((string) $c->cnpj_digitos) === 14 ? substr($c->cnpj_digitos, 0, 8) : null;
            $filiais[$codigo]['total'] = ($filiais[$codigo]['total'] ?? 0) + 1;

            if ($raiz !== null) {
                $filiais[$codigo]['raizes'][$raiz] = ($filiais[$codigo]['raizes'][$raiz] ?? 0) + 1;
                $codigosDaRaiz[$raiz][$codigo] = true;
            }
        }

        $raizesDaRede = [];
        foreach ($ligados as $codigo => $v) {
            foreach (array_keys($filiais[$codigo]['raizes'] ?? []) as $raiz) {
                $raizesDaRede[$v['rede']][$raiz] = true;
            }
        }

        $candidatos = [];
        foreach ($raizesDaRede as $rede => $raizes) {
            $vistos = [];
            foreach (array_keys($raizes) as $raiz) {
                foreach (array_keys($codigosDaRaiz[$raiz] ?? []) as $codigo) {
                    if (isset($ligados[$codigo]) || isset($vistos[$codigo])) {
                        continue;
                    }
                    $vistos[$codigo] = true;

                    $dentro = array_sum(array_intersect_key($filiais[$codigo]['raizes'], $raizes));
                    if ($dentro * 2 >= $filiais[$codigo]['total']) {
                        $candidatos[$codigo][$rede] = self::MESMA_EMPRESA;
                    }
                }
            }
        }

        // Raiz que já está em DUAS redes (a mesma empresa opera ATACADÃO e CARREFOUR BAIRRO):
        // o código sem nome de nenhuma das duas não tem como ser decidido. Fica de fora.
        foreach ($candidatos as $codigo => $porRede) {
            if (count($porRede) > 1) {
                $conflitos[] = ['tipo' => 'cliente', 'codigo' => (string) $codigo, 'nivel' => self::MESMA_EMPRESA, 'redes' => array_keys($porRede), 'decidido' => null];
                unset($candidatos[$codigo]);
            }
        }

        return $this->resolver($candidatos, $peso, 'cliente', $conflitos);
    }

    /**
     * @param  list<array>  $redes
     * @param  array<int, string>  $alvos
     * @param  array<int, int>  $peso
     * @return array<int, array{rede: int, nivel: string}>
     */
    private function leads(array $redes, array $alvos, array $peso, array &$ambiguas, array &$conflitos): array
    {
        $raizesDeCliente = [];
        foreach (DB::table('clientes')->whereNotNull('cnpj_digitos')->select('cnpj_digitos')->cursor() as $c) {
            if (strlen((string) $c->cnpj_digitos) === 14) {
                $raizesDeCliente[substr($c->cnpj_digitos, 0, 8)] = true;
            }
        }

        $linhas = [];
        foreach (DB::table('leads')->where('status', '!=', 'excluido')->select('id', 'nome', 'razao_social', 'nome_fantasia', 'cnpj')->cursor() as $l) {
            $digitos = preg_replace('/\D/', '', (string) $l->cnpj);
            if (strlen($digitos) === 14 && isset($raizesDeCliente[substr($digitos, 0, 8)])) {
                continue;
            }

            $linhas[] = (object) [
                'cod_cliente' => 'L'.$l->id,
                'loja' => '0001',
                'cod_grupo' => '9998',
                'cod_segmento' => '',
                'razao_social' => $l->razao_social ?: $l->nome,
                'nome_fantasia' => $l->nome_fantasia ?: $l->nome,
            ];
        }

        $catalogo = $this->porCliente->catalogoDe($linhas, comNomesDeGrupo: false);

        $candidatos = [];
        foreach (array_keys($redes) as $i) {
            // Lead não tem segmento do TOTVS: vale qualquer um, só as travas de nome.
            $r = $this->porCliente->sugerir($alvos[$i], null, $catalogo);

            if ($r['ambiguo']) {
                $ambiguas[] = ['rede' => $i, 'tipo' => 'lead'];

                continue;
            }

            foreach ($r['clientes'] as $codigo) {
                $candidatos[$codigo][$i] = self::NOME;
            }
        }

        $saida = [];
        foreach ($this->resolver($candidatos, $peso, 'lead', $conflitos) as $codigo => $v) {
            $saida[(int) substr($codigo, 1)] = $v;
        }

        return $saida;
    }
}
