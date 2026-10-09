<?php

namespace App\Services\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategicaVinculo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sugestão de vínculo conta → clientes pelo NOME DOS CLIENTES (fantasia e razão social),
 * e não pelo nome do grupo do TOTVS como faz `SugestaoDeVinculo`.
 *
 * Por que existe (medido em produção em 06/10/2026): só 110 das 404 contas tinham vínculo.
 * O nome do grupo costuma não ser o da marca ("BRASIL PARK" mora num grupo de outro nome)
 * e um terço da base está no 9998, sem grupo nenhum (C VALE, NORMATEL, REDE FURNAS,
 * ENGEPARK). O nome fantasia da filial, ao contrário, quase sempre é a marca.
 *
 * As travas, cada uma contra um falso positivo visto na base real:
 *
 * 1. **Começo do nome, com fronteira de palavra.** "DROGARIA ARAUJO" NÃO casa "DROGARIA
 *    MODENA & ARAUJO" nem "BRASIL PARK" casa "SANTA LOLLA - BRASIL PARK SHOPPING".
 *    Compara as palavras de marca (`SugestaoDeVinculo::marca()`): o lado mais curto tem
 *    que ser o começo do mais longo — "ENGEPARK VALLET E PARKING" casa "ENGEPARK VALLET".
 * 2. **O lado curto tem que identificar alguma coisa**: 4+ letras e, se for uma palavra
 *    só, não genérica ("BRASIL", "FARMA", "CENTER").
 * 3. **Filial avulsa só no segmento da conta.** "REDE FURNAS" (posto) não leva FURNAS
 *    CENTRAIS ELÉTRICAS. ⚠️ Exceção: nome IGUAL ao da conta, COMO ESTÁ ESCRITO
 *    (`escrita()`), vale em qualquer segmento — o TOTVS tem 184 clientes com nome de
 *    comida cadastrados como SUPERMERCADISTA, e a conta AMOR AOS PEDACOS não achava o
 *    cliente "AMOR AOS PEDACOS" (2026-10-09). Igual pela marca não basta: "NIPPON" =
 *    "SUPERMERCADO NIPPON" e "POSTO OPÇÃO" = "SUPERMERCADO OPÇÃO" na marca.
 * 4. **Grupo só se a MAIORIA das filiais dele casar pelo nome** (e ao menos uma no segmento
 *    da conta). É o que traz as filiais com nome diferente da mesma rede ("AUTO BRASIL
 *    ESTAC") sem trazer o grupo de outra empresa que tem uma loja com nome parecido.
 * 5. **Código avulso, idem**: só se a maioria das filiais do código casar. Um código como
 *    o 000800 (centenas de escolas) não entra por causa de uma filial.
 * 6. **9998 nunca vira grupo** (`GRUPOS_PROIBIDOS`); as filiais dele entram como código.
 *
 * A comparação ignora variação de GRAFIA (`grafia()`), nos dois lados: a conta "BURGUER
 * KING" não casava o grupo BURGER KING (66 lojas) por uma letra (Tony, 2026-10-09).
 * ⚠️ Distância de edição foi medida e descartada: casava GEPARK com LEPARK e SERTAO com
 * BERTAO. Só variações de escrita da MESMA palavra, nunca letra trocada.
 *
 * Grupo ou código que casa com DUAS contas é descartado pelo comando, nunca escolhido.
 */
class VinculoPorCliente
{
    private const MINIMO_COMPACTO = 4;

    private const MINIMO_PALAVRA_UNICA = 6;

    /** Acima disto a conta é ambígua (nome comum demais): não sugere nada. */
    public const MAXIMO_CODIGOS = 80;

    public function __construct(private readonly SugestaoDeVinculo $nomes)
    {
    }

    /**
     * Os clientes em memória, UMA vez por rodada (92 k filiais).
     *
     * @return array{filiais: list<array>, indice: array<string, list<array{0: int, 1: list<string>}>>, porGrupo: array<string, int>, porCodigo: array<string, int>}
     */
    public function catalogo(): array
    {
        $filiais = [];
        $indice = [];
        $porGrupo = [];
        $porCodigo = [];
        $segmentosDoGrupo = [];

        $linhas = DB::table('clientes')
            ->select('cod_cliente', 'loja', 'cod_grupo', 'cod_segmento', 'razao_social', 'nome_fantasia')
            ->orderBy('id')
            ->cursor();

        foreach ($linhas as $c) {
            // Endereço de entrega: o "nome fantasia" ali é rua ou bairro ("IPIRANGA", "RUA
            // CAYOWAA, 45") e casava conta de outra empresa. Também não entra na contagem
            // da maioria — é a mesma empresa da loja comercial.
            if (in_array(mb_substr((string) $c->loja, 0, 1), Cliente::PREFIXOS_ENTREGA, true)) {
                continue;
            }

            $i = count($filiais);
            $grupo = (string) $c->cod_grupo;
            $codigo = (string) $c->cod_cliente;

            $filiais[] = [
                'codigo' => $codigo,
                'grupo' => $grupo,
                'segmento' => (string) $c->cod_segmento,
                'nome' => (string) ($c->nome_fantasia ?: $c->razao_social),
            ];
            $porGrupo[$grupo] = ($porGrupo[$grupo] ?? 0) + 1;
            $segmentosDoGrupo[$grupo][(string) $c->cod_segmento] = true;
            $porCodigo[$codigo] = ($porCodigo[$codigo] ?? 0) + 1;

            $vistos = [];
            foreach ([$c->nome_fantasia, $c->razao_social] as $nome) {
                $marca = $this->nomes->marca((string) $nome);
                $grafia = $this->grafia($marca);
                $compacto = implode('', $grafia);

                if (strlen($compacto) < self::MINIMO_COMPACTO || isset($vistos[$compacto])) {
                    continue;
                }

                $vistos[$compacto] = true;
                $indice[substr($compacto, 0, 3)][] = [$i, $marca, $grafia, $this->escrita((string) $nome)];
            }
        }

        // O NOME DO GRUPO também identifica: o grupo 1305 se chama KOPENHAGEM, e as lojas
        // dele, BABOO, BAIXADO, "KOP SP HOSPITAL…" — nenhuma casaria pelo nome (2026-10-09).
        $nomesDeGrupo = [];
        foreach (DB::table('grupos_cliente')->whereNotIn('codigo', ContaEstrategicaVinculo::GRUPOS_PROIBIDOS)->get(['codigo', 'nome']) as $g) {
            $marca = $this->nomes->marca((string) $g->nome);
            $grafia = $this->grafia($marca);

            if (strlen(implode('', $grafia)) >= self::MINIMO_COMPACTO) {
                $nomesDeGrupo[substr(implode('', $grafia), 0, 3)][] = [(string) $g->codigo, $marca, $grafia, $this->escrita((string) $g->nome), (string) $g->nome];
            }
        }

        return ['filiais' => $filiais, 'indice' => $indice, 'porGrupo' => $porGrupo, 'porCodigo' => $porCodigo,
            'nomesDeGrupo' => $nomesDeGrupo, 'segmentosDoGrupo' => $segmentosDoGrupo];
    }

    /**
     * @return array{grupos: list<string>, clientes: list<string>, ambiguo: bool, exemplos: list<string>}
     */
    public function sugerir(string $nomeConta, string $codigoSegmento, array $catalogo): array
    {
        $casadas = [];

        foreach ($this->alternativas($nomeConta) as [$alvo, $alvoEscrito]) {
            $alvoGrafia = $this->grafia($alvo);

            foreach ($catalogo['indice'][substr(implode('', $alvoGrafia), 0, 3)] ?? [] as [$i, $marca, $grafia, $escrita]) {
                if (! ($casadas[$i] ?? false) && $this->casa($alvo, $alvoGrafia, $marca, $grafia)) {
                    // true = nome igual como escrito: vale fora do segmento da conta (trava 3).
                    $casadas[$i] = $alvoEscrito === $escrita;
                }
            }
        }

        $filiais = $catalogo['filiais'];
        $casadasPorGrupo = [];
        $grupoComSegmento = [];

        $noSegmento = fn (int $i) => $casadas[$i] || $filiais[$i]['segmento'] === $codigoSegmento;

        foreach (array_keys($casadas) as $i) {
            $f = $filiais[$i];
            $casadasPorGrupo[$f['grupo']] = ($casadasPorGrupo[$f['grupo']] ?? 0) + 1;

            if ($noSegmento($i)) {
                $grupoComSegmento[$f['grupo']] = true;
            }
        }

        $grupos = [];
        foreach ($casadasPorGrupo as $grupo => $n) {
            if (in_array((string) $grupo, ContaEstrategicaVinculo::GRUPOS_PROIBIDOS, true) || ! isset($grupoComSegmento[$grupo])) {
                continue;
            }

            if ($n * 2 >= $catalogo['porGrupo'][$grupo]) {
                $grupos[] = (string) $grupo;
            }
        }

        // Grupo pelo próprio nome: mesmas travas de nome, e com loja no segmento da conta
        // (ou nome igual como escrito) — o "GRUPO KOPENHAGEM" da fábrica não entra numa
        // conta de ALIMENTACAO.
        $exemplosDeGrupo = [];
        foreach ($this->alternativas($nomeConta) as [$alvo, $alvoEscrito]) {
            $alvoGrafia = $this->grafia($alvo);

            foreach ($catalogo['nomesDeGrupo'][substr(implode('', $alvoGrafia), 0, 3)] ?? [] as [$grupo, $marca, $grafia, $escrita, $nome]) {
                if (in_array($grupo, $grupos, true) || ! $this->casa($alvo, $alvoGrafia, $marca, $grafia)) {
                    continue;
                }

                if ($alvoEscrito === $escrita || isset($catalogo['segmentosDoGrupo'][$grupo][$codigoSegmento])) {
                    $grupos[] = $grupo;
                    $exemplosDeGrupo[] = $nome;
                }
            }
        }

        $casadasPorCodigo = [];
        $exemplos = $exemplosDeGrupo;
        foreach (array_keys($casadas) as $i) {
            $f = $filiais[$i];

            if (in_array($f['grupo'], $grupos, true)) {
                $exemplos[] = $f['nome'];

                continue;
            }

            if ($noSegmento($i)) {
                $casadasPorCodigo[$f['codigo']] = ($casadasPorCodigo[$f['codigo']] ?? 0) + 1;
                $exemplos[] = $f['nome'];
            }
        }

        $clientes = [];
        foreach ($casadasPorCodigo as $codigo => $n) {
            if ($n * 2 >= $catalogo['porCodigo'][$codigo]) {
                $clientes[] = (string) $codigo;
            }
        }

        if (count($clientes) > self::MAXIMO_CODIGOS || count($grupos) > SugestaoDeVinculo::MAXIMO_GRUPOS) {
            return ['grupos' => [], 'clientes' => [], 'ambiguo' => true, 'exemplos' => []];
        }

        sort($grupos);
        sort($clientes);

        return [
            'grupos' => $grupos,
            'clientes' => $clientes,
            'ambiguo' => false,
            'exemplos' => array_values(array_slice(array_unique($exemplos), 0, 3)),
        ];
    }

    /**
     * "ASFAR / DESCONTO FACIL" são duas marcas da mesma conta; cada uma é procurada.
     *
     * @return list<array{0: list<string>, 1: string}> marca e `escrita()` de cada parte
     */
    private function alternativas(string $nome): array
    {
        $saida = [];

        foreach (preg_split('/[\/()]/', $nome) as $parte) {
            $marca = $this->nomes->marca($parte);

            if ($this->identifica($marca)) {
                $saida[] = [$marca, $this->escrita($parte)];
            }
        }

        return $saida;
    }

    /**
     * O nome como está escrito, para a exceção de segmento: sem pontuação, sufixo
     * societário, conectivo e agrupador (REDE, GRUPO, LOJAS), mas COM a palavra de tipo —
     * "SUPERMERCADO NIPPON" não é "NIPPON". Mesma `grafia()` da comparação.
     */
    private function escrita(string $nome): string
    {
        $palavras = preg_split('/[^A-Z0-9]+/', mb_strtoupper(Str::ascii($nome)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $palavras = array_values(array_diff($palavras, [
            'REDE', 'GRUPO', 'LOJA', 'LOJAS', 'SA', 'S', 'A', 'LTDA', 'ME', 'EPP', 'EIRELI',
            'DO', 'DA', 'DE', 'DOS', 'DAS', 'E',
        ]));

        return implode('', $this->grafia($palavras));
    }

    /**
     * A forma de comparar, palavra a palavra: número separado das letras ("SPOLETO2" =
     * "SPOLETO 2"), PH→F, Y→I, GUE/GUI→GE/GI ("BURGUER" = "BURGER"), letra dobrada
     * vira uma ("COOPERFARMA" = "COPERFARMA", "MILLIUM" = "MILIUM"), plural de palavra
     * com 5+ letras ("BOLOS" = "BOLO") e M final vira N ("KOPENHAGEM" = "KOPENHAGEN").
     *
     * ⚠️ Só para comparar. Se o nome identifica alguma coisa (`identifica()`, palavra
     * genérica) continua sendo decidido pelo nome escrito: "PIZZAS" vira "PIZA", que não
     * está na lista de genéricas.
     *
     * @param  list<string>  $marca
     * @return list<string>
     */
    public function grafia(array $marca): array
    {
        $saida = [];

        foreach ($marca as $palavra) {
            foreach (preg_split('/(?<=[A-Z])(?=[0-9])|(?<=[0-9])(?=[A-Z])/', $palavra) as $p) {
                $p = str_replace(['PH', 'Y'], ['F', 'I'], $p);
                $p = preg_replace('/GU([EI])/', 'G$1', $p);
                $p = preg_replace('/([A-Z])\1+/', '$1', $p);

                if (strlen($p) >= 5 && str_ends_with($p, 'S')) {
                    $p = substr($p, 0, -1);
                }

                $saida[] = preg_replace('/M$/', 'N', $p);
            }
        }

        return $saida;
    }

    /** @param  list<string>  $marca */
    private function identifica(array $marca): bool
    {
        if (strlen(implode('', $marca)) < self::MINIMO_COMPACTO) {
            return false;
        }

        return ! (count($marca) === 1 && $this->nomes->palavraGenerica($marca[0]));
    }

    /**
     * O nome mais curto é o começo do mais longo, com fronteira de palavra no longo.
     * Compara o compacto: "SERVI PARK" casa "SERVIPARK" e "D 1000" casa "D1000".
     *
     * ⚠️ Quando o nome do CLIENTE é o curto, uma palavra só não basta: "POSTO DO PARQUE"
     * (marca PARQUE) casava a conta "Posto Parque Dez". Nome de filial curto e genérico é
     * comum; nome de conta curto não — a conta foi escrita pela diretoria.
     *
     * Recebe o nome escrito (para `identifica()`) e a `grafia()` (para comparar).
     *
     * @param  list<string>  $conta
     * @param  list<string>  $contaGrafia
     * @param  list<string>  $cliente
     * @param  list<string>  $clienteGrafia
     */
    private function casa(array $conta, array $contaGrafia, array $cliente, array $clienteGrafia): bool
    {
        $clienteECurto = strlen(implode('', $clienteGrafia)) < strlen(implode('', $contaGrafia));
        [$curtoEscrito, $curto, $longo] = $clienteECurto
            ? [$cliente, $clienteGrafia, $contaGrafia]
            : [$conta, $contaGrafia, $clienteGrafia];

        if ($clienteECurto && count($cliente) === 1) {
            return false;
        }

        if (! $this->identifica($curtoEscrito)) {
            return false;
        }

        $alvo = implode('', $curto);

        if (! str_starts_with(implode('', $longo), $alvo)) {
            return false;
        }

        // Uma palavra só, casando o começo de um nome mais longo, precisa ser comprida:
        // "MINHA" pegava "MINHA DROGARIA" e "LOJA ELETRICA" pegava "ELETRICA NICOLUCCI".
        // Nome IGUAL ("DROGARIA VIDA" = "VIDA FARMACIAS") continua valendo com 4 letras.
        if (count($curto) === 1 && $alvo !== implode('', $longo) && strlen($alvo) < self::MINIMO_PALAVRA_UNICA) {
            return false;
        }

        $acumulado = '';
        foreach ($longo as $palavra) {
            $acumulado .= $palavra;

            if ($acumulado === $alvo) {
                return true;
            }

            if (strlen($acumulado) > strlen($alvo)) {
                return false;
            }
        }

        return false;
    }
}
