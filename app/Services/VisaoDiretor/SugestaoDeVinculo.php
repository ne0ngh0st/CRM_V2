<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sugestão de vínculo conta→grupo na carga inicial da Visão Diretor.
 *
 * Conservadora de propósito: sugestão errada parece certa (e o vendedor trata a conta
 * como "já casada"); falta de sugestão só vai para o relatório, e se vincula na tela.
 *
 * Duas camadas, medidas em 18/09/2026 contra o palma_v2 (2.450 grupos, 92 k clientes) e
 * a planilha real (388 contas):
 *
 * 1. **Prefixo com fronteira de token.** O compacto da conta (depois de tirar LOJAS /
 *    REDE / GRUPO / POSTO do começo) é o compacto do grupo, ou a concatenação de um
 *    prefixo dos tokens dele. "ESTAPAR" casa "ESTAPAR BA"; "Lojas Renner" casa "RENNER".
 *    ⚠️ NÃO exige segmento: Magazine Luiza no TOTVS é 115, não 108, e o nome está certo.
 *    Sem a fronteira, "GRUPOFARMA" casava "GRUPO FARMAVOCE" (concatenação no meio do token).
 *
 * 2. **Token distintivo + maioria no segmento.** Quando o prefixo não alcança
 *    ("Raia Drogasil" × grupo "DROGASIL"). Palavra genérica (FARMA, SORVETES, MAGAZINE)
 *    não conta. Sem a trava de segmento, "Drogaria São Paulo" casava prefeitura e
 *    aeroporto — 789 falsos positivos na mesma base.
 *
 * Prefixo sozinho: 47/383. Token solto: 240 com 789 FPs. Combinada (com ESTACIONAMENTOS
 * como genérico, senão 12 contas LEAD herdavam 47 lojas de um grupo só): ~99 contas,
 * 173 grupos, 0 ambígua.
 *
 * ⚠️ Só a carga inicial usa isto. Edição na tela passa por `BuscaDeVinculo` (a pessoa
 * escolhe). Não misturar os dois: um é palpite, o outro é busca.
 */
class SugestaoDeVinculo
{
    /** Tirados só do COMEÇO do nome da conta, e só se sobrar outra palavra. */
    private const PREFIXOS = ['POSTOS', 'POSTO', 'REDE', 'GRUPO', 'LOJAS'];

    /**
     * Depois de tirar o prefixo, estes restos sozinhos casariam grupos demais
     * ("POSTO CENTRAL" → CENTRAL*, "Rede Stop" → STOP*).
     */
    private const RESTOS_GENERICOS = ['CENTRAL', 'CENTER', 'STOP', 'BRASIL', 'SUPER', 'HIPER', 'NOVA', 'NOVO'];

    /**
     * Palavra que descreve o TIPO, não a marca. Não dispara token e não entra na
     * concatenação de fracos (senão "RM FARMA" casa todo mundo com FARMA).
     */
    private const GENERICOS = [
        'LOJA', 'LOJAS', 'REDE', 'GRUPO', 'POSTO', 'POSTOS',
        'DROGARIA', 'DROGARIAS', 'FARMACIA', 'FARMACIAS', 'FARMA', 'DROGA', 'DROGAS',
        'SUPERMERCADO', 'SUPERMERCADOS', 'HIPER', 'HIPERMERCADO', 'HIPERMERCADOS', 'SUPER',
        'ATACADO', 'ATACADISTA', 'COMERCIO', 'COMERCIAL',
        'DISTRIBUIDORA', 'DISTRIBUIDOR', 'BRASIL', 'BRASILEIRA', 'BRASILEIRO',
        'SA', 'LTDA', 'ME', 'EIRELI', 'CIA', 'COMPANHIA',
        'DO', 'DA', 'DE', 'DOS', 'DAS', 'E', 'THE',
        'MERCADO', 'MERCADOS', 'CENTER', 'CENTRE', 'CENTRAL', 'SHOPPING',
        'MAGAZINE', 'ARMAZEM', 'SORVETE', 'SORVETES', 'STOP',
        'ESTACIONAMENTO', 'ESTACIONAMENTOS', 'PARKING', 'GARAGEM', 'GARAGENS',
        'CONVENIENCIA', 'CONVENIENCIAS', 'SERVICO', 'SERVICOS',
        'EMPRESA', 'ADMINISTRADORA', 'SOCIEDADE',
    ];

    /** Curto demais ou nome demais comum: sozinho não casa; concatenado com o vizinho, sim ("SAO"+"JOAO"). */
    private const FRACOS = ['SAO', 'JOAO', 'PAULO', 'MARIA', 'JOSE', 'SANTA', 'SANTO', 'NORTE', 'SUL'];

    private const MINIMO_PREFIXO = 5;

    private const MINIMO_TOKEN = 5;

    /** Acima disto a conta é ambígua: o comando relata e não grava vínculo. */
    public const MAXIMO_GRUPOS = 40;

    /**
     * Catálogo em memória (grupos + mistura de segmento). Carregar UMA vez por import —
     * a mistura é um GROUP BY em `clientes`, e sugerir() roda por conta.
     *
     * @return Collection<int, array{codigo: string, nome: string, compacto: string, tokensTodos: list<string>, tokensDistintivos: list<string>, total: int, porSeg: array<string, int>}>
     */
    public function catalogo(): Collection
    {
        $mix = DB::table('clientes')
            ->selectRaw('cod_grupo, cod_segmento, COUNT(*) as n')
            ->groupBy('cod_grupo', 'cod_segmento')
            ->get()
            ->groupBy('cod_grupo');

        return GrupoCliente::query()
            ->whereNotIn('codigo', ContaEstrategicaVinculo::GRUPOS_PROIBIDOS)
            ->get(['codigo', 'nome'])
            ->map(function (GrupoCliente $g) use ($mix) {
                $linhas = $mix->get($g->codigo, collect());

                return [
                    'codigo' => $g->codigo,
                    'nome' => $g->nome,
                    'compacto' => $this->compacto($g->nome),
                    'tokensTodos' => $this->tokensTodos($g->nome),
                    'tokensDistintivos' => $this->tokensDistintivos($g->nome),
                    'total' => (int) $linhas->sum('n'),
                    'porSeg' => $linhas->mapWithKeys(fn ($r) => [(string) $r->cod_segmento => (int) $r->n])->all(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{codigo: string, nome: string, compacto: string, tokensTodos: list<string>, tokensDistintivos: list<string>, total: int, porSeg: array<string, int>}>  $catalogo
     * @return array{grupos: Collection<int, array{codigo: string, nome: string}>, ambiguo: bool}
     */
    public function sugerir(string $nome, string $codigoSegmento, Collection $catalogo): array
    {
        $alvo = $this->alvoPrefixo($nome);
        $tokens = $this->tokensDistintivos($nome);

        $prefixo = $catalogo->filter(fn (array $g) => $this->casaPrefixo($alvo, $g))->values();
        $token = $catalogo
            ->filter(fn (array $g) => $this->casaToken($tokens, $codigoSegmento, $g))
            ->reject(fn (array $g) => $prefixo->contains(fn (array $p) => $p['codigo'] === $g['codigo']))
            ->values();

        $hits = $prefixo->concat($token);

        if ($hits->count() > self::MAXIMO_GRUPOS) {
            $hits = $prefixo;
        }

        if ($hits->count() > self::MAXIMO_GRUPOS) {
            return ['grupos' => collect(), 'ambiguo' => true];
        }

        return [
            'grupos' => $hits->map(fn (array $g) => ['codigo' => $g['codigo'], 'nome' => $g['nome']])->values(),
            'ambiguo' => false,
        ];
    }

    private function alvoPrefixo(string $nome): ?string
    {
        $palavras = explode(' ', $this->normalizar($nome));

        while (count($palavras) > 1 && in_array($palavras[0], self::PREFIXOS, true)) {
            array_shift($palavras);
        }

        $alvo = $this->compacto(implode(' ', $palavras));

        if (strlen($alvo) < self::MINIMO_PREFIXO || in_array($alvo, self::RESTOS_GENERICOS, true)) {
            return null;
        }

        return $alvo;
    }

    /** @param  array{compacto: string, tokensTodos: list<string>}  $grupo */
    private function casaPrefixo(?string $alvo, array $grupo): bool
    {
        if ($alvo === null) {
            return false;
        }

        if ($grupo['compacto'] === $alvo) {
            return true;
        }

        if (! str_starts_with($grupo['compacto'], $alvo)) {
            return false;
        }

        $acc = '';
        foreach ($grupo['tokensTodos'] as $token) {
            $acc .= $token;
            if ($acc === $alvo) {
                return true;
            }
            if (strlen($acc) > strlen($alvo)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokensConta
     * @param  array{tokensDistintivos: list<string>, total: int, porSeg: array<string, int>}  $grupo
     */
    private function casaToken(array $tokensConta, string $codigoSegmento, array $grupo): bool
    {
        if ($tokensConta === [] || $grupo['tokensDistintivos'] === []) {
            return false;
        }

        if (count(array_intersect($tokensConta, $grupo['tokensDistintivos'])) === 0) {
            return false;
        }

        if ($grupo['total'] === 0) {
            return true;
        }

        $noSeg = (int) ($grupo['porSeg'][$codigoSegmento] ?? 0);

        return $noSeg >= ($grupo['total'] / 2);
    }

    /** @return list<string> */
    private function tokensTodos(string $texto): array
    {
        return preg_split('/[^A-Z0-9]+/', $this->normalizar($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<string> */
    private function tokensDistintivos(string $texto): array
    {
        $out = [];
        $fraco = '';

        foreach ($this->tokensTodos($texto) as $p) {
            if (in_array($p, self::GENERICOS, true)) {
                $this->despejarFraco($fraco, $out);
                $fraco = '';
                continue;
            }

            if (strlen($p) < self::MINIMO_TOKEN || in_array($p, self::FRACOS, true)) {
                $fraco .= $p;
                continue;
            }

            $this->despejarFraco($fraco, $out);
            $fraco = '';
            $out[] = $p;
        }

        $this->despejarFraco($fraco, $out);

        return array_values(array_unique($out));
    }

    /** @param  list<string>  $out */
    private function despejarFraco(string $fraco, array &$out): void
    {
        if (strlen($fraco) >= self::MINIMO_TOKEN) {
            $out[] = $fraco;
        }
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper(Str::ascii($texto))));
    }

    private function compacto(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]/', '', $this->normalizar($texto));
    }
}
