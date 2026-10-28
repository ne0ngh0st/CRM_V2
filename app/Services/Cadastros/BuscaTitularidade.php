<?php

namespace App\Services\Cadastros;

use App\Models\Cliente;
use App\Models\ClienteParaCadastro;
use App\Models\User;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Collection;

/**
 * "Quem cuida do cliente?" — responde de quem é um cliente, para quem NÃO o tem na
 * carteira.
 *
 * ⚠️ ESTA É A ÚNICA CONSULTA DE CLIENTE DO SISTEMA QUE IGNORA O ESCOPO DO VENDEDOR, e é
 * de propósito. Todo o resto (`CarteiraController::scopeQuery`, `autorizarCliente`) filtra
 * por `cod_vendedor`, e é justamente por isso que a Carteira não consegue responder "esse
 * CNPJ já é de alguém?". Sem esta busca, o vendedor descobre que pisou no cliente do
 * colega depois de ligar — ou pede cadastro de um cliente que já existe.
 *
 * ⚠️ NÃO "CORRIGIR" ISTO ADICIONANDO ESCOPO. Se aparecer uma auditoria dizendo que aqui
 * falta filtro por vendedor, a resposta é este comentário: o escopo ausente é a feature.
 * O que limita o dano é o CONTEÚDO — só titularidade. Sem status, sem data de última
 * compra, sem telefone, sem e-mail, sem valores. A pergunta respondida é "posso
 * prospectar?", não "como está a carteira do colega?". Manter essa lista curta é o que
 * mantém a decisão defensável; cada campo novo aqui precisa ser decisão consciente.
 */
class BuscaTitularidade
{
    /** Abaixo disso a busca por nome traria meia base. */
    public const MINIMO_CARACTERES = 3;

    private const LIMITE = 30;

    public function __construct(private readonly NomeVendedorResolver $nomeVendedor) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $termo): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < self::MINIMO_CARACTERES) {
            return [];
        }

        $clientes = $this->clientes($termo);
        $pendentes = $this->solicitacoesPendentes($termo);

        return $clientes->concat($pendentes)->take(self::LIMITE)->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function clientes(string $termo): Collection
    {
        return $this->comResponsaveis($this->clientesQueCasam($termo));
    }

    /**
     * Mesma resposta de `buscar()`, mas pelas PALAVRAS do termo (ver
     * `clientesPorPalavras`). Só o assistente de IA chama, e só depois de `buscar()`
     * não achar nada: a tela continua exata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarPorPalavras(string $termo): array
    {
        return $this->comResponsaveis($this->clientesPorPalavras($termo))->values()->all();
    }

    /**
     * @param  Collection<int, Cliente>  $clientes
     * @return Collection<int, array<string, mixed>>
     */
    private function comResponsaveis(Collection $clientes): Collection
    {
        $responsaveis = $this->responsaveisPorCodigo($clientes->pluck('cod_vendedor')->filter()->unique()->all());

        return $clientes->map(fn (Cliente $c) => [
            'tipo' => 'cliente',
            'razaoSocial' => $c->razao_social,
            'nomeFantasia' => $c->nome_fantasia,
            'cnpj' => $c->cnpj,
            'codCliente' => $c->cod_cliente,
            'loja' => $c->loja,
            'codVendedor' => $c->cod_vendedor,
            ...($responsaveis[$c->cod_vendedor] ?? ['responsaveis' => [], 'supervisor' => null]),
        ]);
    }

    /**
     * Os clientes que casam com o termo — documento, código do TOTVS ou nome.
     *
     * Sem `$codVendedores`, ignora o escopo (é a feature desta classe, ver o docblock).
     * Com ele, devolve só a carteira: é a busca do assistente de IA, que reaproveita a
     * mesma lógica de casamento — e os mesmos números medidos abaixo — em vez de ter uma
     * segunda (Regra de ouro nº 8).
     *
     * @param  array<string>|null  $codVendedores
     * @return Collection<int, Cliente>
     */
    public function clientesQueCasam(string $termo, ?array $codVendedores = null, int $limite = self::LIMITE): Collection
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D/', '', $termo) ?? '';

        if ($codVendedores === []) {
            return collect();
        }

        // "015076/0001" é código + loja (o formato de Cliente::codigoTotvs), não um CNPJ.
        // Sem este corte, os dígitos colados viram prefixo de CNPJ e a filial certa some.
        if (preg_match('/^(\d{1,6})\s*\/\s*(\d{1,4})$/', $termo, $partes)) {
            return $this->porCodigoELoja($partes[1], $partes[2], $codVendedores, $limite);
        }

        $query = Cliente::query()
            ->select(['id', 'cod_cliente', 'loja', 'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_grupo'])
            ->when($codVendedores !== null, fn ($q) => $q->whereIn('cod_vendedor', $codVendedores));

        /*
         * ⚠️ Caminho rápido por PREFIXO MASCARADO, não por REPLACE().
         *
         * O legado casava a raiz do CNPJ com
         * `SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(cnpj,...)))), 1, 8)`, que além de
         * depender da coluna proibida `raiz_cnpj` não é sargable: envolver a coluna numa
         * função joga fora o índice e varre as 92 mil linhas.
         *
         * Aqui os dígitos digitados são remontados na máscara e a busca vira
         * `cnpj LIKE 'prefixo%'`, que USA o índice de `cnpj`. Conferido no banco antes de
         * escrever isto: 91.451 registros estão mascarados como CNPJ, 746 como CPF
         * (pessoa física) e ZERO gravados só com dígitos — por isso as duas máscaras, e
         * por isso não há caminho de "só dígitos" a manter.
         */
        if (mb_strlen($digitos) >= 3) {
            $query->where(function ($q) use ($digitos, $termo) {
                $q->where('cnpj', 'like', $this->prefixoCnpj($digitos).'%')
                    ->orWhere('cnpj', 'like', $this->prefixoCpf($digitos).'%')
                    ->orWhere('cod_cliente', $termo);
            });
            $clientes = $query->orderBy('razao_social')->limit($limite)->get();

            // Dígitos que não acharam documento caem para o nome: um número no meio da
            // razão social ("Casa 24 Horas") não pode sumir da resposta.
            if ($clientes->isEmpty()) {
                $clientes = $this->porNome($termo, $codVendedores, $limite);
            }
        } else {
            $clientes = $this->porNome($termo, $codVendedores, $limite);
        }

        return $clientes;
    }

    /**
     * Filial pelo par código/loja. Se a loja não bater (padding diferente), devolve as
     * filiais daquele código: melhor a lista certa do que "não existe".
     *
     * @param  array<string>|null  $codVendedores
     * @return Collection<int, Cliente>
     */
    private function porCodigoELoja(string $codigo, string $loja, ?array $codVendedores, int $limite): Collection
    {
        $base = fn () => Cliente::query()
            ->select(['id', 'cod_cliente', 'loja', 'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_grupo'])
            ->when($codVendedores !== null, fn ($q) => $q->whereIn('cod_vendedor', $codVendedores))
            ->where('cod_cliente', $codigo)
            ->orderBy('razao_social')
            ->limit($limite);

        $daLoja = $base()->where('loja', $loja)->get();

        return $daLoja->isNotEmpty() ? $daLoja : $base()->get();
    }

    /**
     * Busca por nome, em dois degraus — e a ordem importa muito.
     *
     * ⚠️ MEDIDO com os 92.209 clientes reais, e a diferença é de duas ordens de grandeza:
     *
     *   |          | `LIKE 'termo%'` | `LIKE '%termo%'` |
     *   |----------|----------------:|-----------------:|
     *   | MERCADO  |          1,5 ms |          44,7 ms |
     *   | PADARIA  |          2,1 ms |         348,7 ms |
     *   | KNTT     |          2,1 ms |         511,8 ms |
     *
     * O contra-intuitivo é que o termo RARO é o mais caro. Com `ORDER BY razao_social
     * LIMIT 30`, o MySQL percorre o índice em ordem e para na 30ª linha que casar — para
     * "MERCADO" isso acontece cedo; para "KNTT", que tem um punhado de ocorrências, ele
     * percorre as 92 mil entradas antes de desistir. Ou seja: quanto mais específica a
     * busca, pior o tempo. É exatamente o tipo de armadilha que só aparece medindo com
     * volume real (Regra de ouro nº 6).
     *
     * Por isso: prefixo primeiro (range no índice), e só quem não achou nada paga o
     * "contém". Quem digita o começo do nome — o caso normal — nunca paga.
     *
     * ⚠️ O prefixo casa `razao_social` OU `nome_fantasia`, e isso SÓ é rápido porque as
     * duas colunas têm índice (migration 2026_09_03_130000). Um OR em que um dos lados
     * não é indexado derruba o plano inteiro: medido em 287 ms contra 1,3 ms. Se alguém
     * acrescentar uma terceira coluna a este OR, tem que indexá-la junto.
     *
     * Números finais do serviço completo, com os 92.209 clientes:
     *
     *   CNPJ (máscara, dígitos ou raiz) :  6-8 ms
     *   nome raro    ("KNTT")           :  9,5 ms   (era 360 ms)
     *   nome comum   ("MERCADO")        : 41 ms
     *   termo sem match por prefixo     : 290 ms   ← único caso caro, e é o mais raro
     *
     * @param  array<string>|null  $codVendedores
     * @return Collection<int, Cliente>
     */
    private function porNome(string $termo, ?array $codVendedores, int $limite): Collection
    {
        $base = fn () => Cliente::query()
            ->select(['id', 'cod_cliente', 'loja', 'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_grupo'])
            ->when($codVendedores !== null, fn ($q) => $q->whereIn('cod_vendedor', $codVendedores))
            ->orderBy('razao_social')
            ->limit($limite);

        $porPrefixo = $base()
            ->where(fn ($q) => $q->where('razao_social', 'like', "{$termo}%")
                ->orWhere('nome_fantasia', 'like', "{$termo}%"))
            ->get();

        if ($porPrefixo->isNotEmpty()) {
            return $porPrefixo;
        }

        return $base()
            ->where(fn ($q) => $q->where('razao_social', 'like', "%{$termo}%")
                ->orWhere('nome_fantasia', 'like', "%{$termo}%"))
            ->get();
    }

    /** Teto de linhas lidas pela busca aproximada antes de pontuar. */
    private const CANDIDATOS_POR_PALAVRAS = 500;

    /** Palavras que não identificam empresa nenhuma: casar por elas traria meia base. */
    private const PALAVRAS_GENERICAS = [
        'LTDA', 'SA', 'ME', 'EPP', 'EIRELI', 'CIA', 'DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM',
    ];

    /**
     * Busca aproximada: as palavras do termo em qualquer ordem, palavra inteira.
     *
     * "C6 Bank" não acha "BANCO C6 S.A" por prefixo nem por "contém": a ordem e a grafia
     * mudam. Aqui cada palavra é procurada inteira (`C6`, para "C6" não casar com
     * "REC61"), e quem casa mais palavras vem primeiro — "C6" sozinho já traz o banco.
     *
     * ⚠️ Varre a tabela (`LIKE '%x%'` não usa índice): medido com os 92 mil clientes,
     * 1,1 s ("C6 Bank") a 3 s (três palavras comuns). Por isso NÃO entra em
     * `clientesQueCasam()` nem na tela "Quem cuida do cliente?": é o último recurso do
     * assistente de IA, que já roda no worker da fila `ia` (Regra nº 9), chamado só
     * quando a busca exata não achou. O resultado sai marcado como aproximado para o
     * modelo confirmar com o usuário.
     *
     * @param  array<string>|null  $codVendedores
     * @return Collection<int, Cliente>
     */
    public function clientesPorPalavras(string $termo, ?array $codVendedores = null, int $limite = 10): Collection
    {
        $palavras = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($termo)) ?: [])
            ->filter(fn (string $p) => mb_strlen($p) >= 2 && ! in_array($p, self::PALAVRAS_GENERICAS, true))
            ->unique()
            ->take(5)
            ->values();

        if ($palavras->isEmpty() || $codVendedores === []) {
            return collect();
        }

        // Filtro barato primeiro (LIKE varre, mas é bem mais leve que REGEXP), já
        // ordenado por quantas palavras casam; palavra inteira e raridade são conferidas
        // em PHP, só nos candidatos.
        // ⚠️ COALESCE: sem ele, cliente sem nome fantasia dá NULL LIKE → NULL, a soma
        // inteira vira NULL e ele some da busca mesmo casando pela razão social.
        $pontos = implode(' + ', array_fill(0, $palavras->count(), "(COALESCE(razao_social, '') LIKE ? OR COALESCE(nome_fantasia, '') LIKE ?)"));
        $ligacoes = $palavras->flatMap(fn (string $p) => ["%{$p}%", "%{$p}%"])->all();

        $candidatos = Cliente::query()
            ->select(['id', 'cod_cliente', 'loja', 'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_grupo'])
            ->when($codVendedores !== null, fn ($q) => $q->whereIn('cod_vendedor', $codVendedores))
            ->whereRaw("({$pontos}) > 0", $ligacoes)
            ->orderByRaw("({$pontos}) DESC", $ligacoes)
            ->limit(self::CANDIDATOS_POR_PALAVRAS)
            ->get();

        $casadas = $candidatos->mapWithKeys(fn (Cliente $c) => [$c->id => $palavras->filter(
            fn (string $p) => preg_match('/\b'.preg_quote($p, '/').'\b/u', mb_strtoupper($c->razao_social.' '.$c->nome_fantasia)) === 1,
        )->all()]);

        // A palavra rara pesa mais: em "C6 Bank", "C6" (o banco) vale mais que "BANK",
        // que aparece em meia dúzia de fintechs. Peso = 1 / quantos candidatos a têm.
        // ⚠️ Os candidatos vêm truncados em 500 (os que mais casam primeiro), então o
        // peso de uma palavra comum é aproximado — basta para desempatar.
        $peso = $palavras->mapWithKeys(fn (string $p) => [
            $p => 1 / max(1, $casadas->filter(fn (array $ps) => in_array($p, $ps, true))->count()),
        ]);

        // Primeiro quem casa MAIS palavras; no empate, a palavra rara pesa mais.
        return $candidatos
            ->filter(fn (Cliente $c) => $casadas[$c->id] !== [])
            ->sortBy([
                fn (Cliente $a, Cliente $b) => count($casadas[$b->id]) <=> count($casadas[$a->id]),
                fn (Cliente $a, Cliente $b) => array_sum(array_map(fn ($p) => $peso[$p], $casadas[$b->id]))
                    <=> array_sum(array_map(fn ($p) => $peso[$p], $casadas[$a->id])),
                fn (Cliente $a, Cliente $b) => strcmp($a->razao_social, $b->razao_social),
            ])
            ->take($limite)
            ->values();
    }

    /**
     * Solicitações de cadastro ainda não atendidas.
     *
     * É o que impede a SEGUNDA solicitação duplicada: o cliente ainda não existe no TOTVS,
     * então não está em `clientes`, mas alguém já pediu. Sem esta metade, a busca diria
     * "não é de ninguém" e o time de Cadastro receberia o mesmo pedido duas vezes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function solicitacoesPendentes(string $termo): Collection
    {
        return ClienteParaCadastro::query()
            ->where('status', 'pendente')
            ->where(fn ($q) => $q->where('razao_social', 'like', "%{$termo}%")
                ->orWhere('nome_fantasia', 'like', "%{$termo}%")
                // ⚠️ A coluna aqui é `cnpj_faturamento`, não `cnpj` — a fila de
                // solicitação tem schema próprio, não é um espelho de `clientes`.
                ->orWhere('cnpj_faturamento', 'like', "%{$termo}%"))
            ->with('user:id,name,display_name')
            ->limit(10)
            ->get()
            ->map(fn (ClienteParaCadastro $s) => [
                'tipo' => 'pendente',
                'razaoSocial' => $s->razao_social,
                'nomeFantasia' => $s->nome_fantasia,
                'cnpj' => $s->cnpj_faturamento,
                'codCliente' => null,
                'loja' => null,
                'codVendedor' => null,
                'responsaveis' => array_filter([$s->user?->display_name ?: $s->user?->name]),
                'supervisor' => null,
            ]);
    }

    /**
     * Nome de quem responde por cada código, mais o supervisor dele.
     *
     * ⚠️ `cod_vendedor` NÃO é único neste projeto — há contas compartilhando código
     * (documentado: um supervisor com 7 códigos e 8 usuários). Por isso `responsaveis` é
     * LISTA: mostrar só o primeiro esconderia metade da resposta justamente nos casos em
     * que a pergunta "quem cuida?" é mais difícil.
     *
     * @param  array<string>  $codigos
     * @return array<string, array{responsaveis: array<string>, supervisor: ?string}>
     */
    private function responsaveisPorCodigo(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        $usuarios = User::query()
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereIn('cod_vendedor', $codigos))
            ->with('vendedorPerfil:id,user_id,cod_vendedor,cod_super')
            ->get(['id', 'name', 'display_name']);

        $supervisores = User::query()
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereIn('cod_vendedor', $usuarios->pluck('vendedorPerfil.cod_super')->filter()->unique()->all()))
            ->with('vendedorPerfil:id,user_id,cod_vendedor')
            ->get(['id', 'name', 'display_name'])
            ->keyBy(fn (User $u) => $u->vendedorPerfil->cod_vendedor);

        $mapa = [];
        foreach ($usuarios as $usuario) {
            $cod = $usuario->vendedorPerfil->cod_vendedor;
            $mapa[$cod] ??= ['responsaveis' => [], 'supervisor' => null];
            $mapa[$cod]['responsaveis'][] = $usuario->display_name ?: $usuario->name;

            $super = $supervisores[$usuario->vendedorPerfil->cod_super] ?? null;
            $mapa[$cod]['supervisor'] ??= $super ? ($super->display_name ?: $super->name) : null;
        }

        /*
         * Código sem conta no CRM ainda tem dono: são 320 dos 444 códigos da base —
         * ex-funcionário, gente de licitação/SAC, e os baldes do TOTVS. Sem este
         * complemento a tela respondia "sem responsável" para 27% dos clientes, que é
         * pior que não responder nada: numa busca cuja pergunta é "posso prospectar?",
         * "sem responsável" soa como autorização.
         *
         * ⚠️ Aqui NÃO entra o degrau do código cru (ver o resolver): quem o TOTVS também
         * não conhece continua saindo como "sem responsável", e o código já aparece na
         * própria linha.
         */
        foreach ($this->nomeVendedor->doTotvs(array_diff($codigos, array_keys($mapa))) as $cod => $nome) {
            $mapa[$cod] = ['responsaveis' => [$nome], 'supervisor' => null];
        }

        return $mapa;
    }

    /** 16729628000162 → "16.729.628/0001-62", truncado no que foi digitado. */
    private function prefixoCnpj(string $digitos): string
    {
        return $this->aplicarMascara($digitos, [2, 3, 3, 4, 2], ['.', '.', '/', '-']);
    }

    /** 12345678901 → "123.456.789-01", truncado no que foi digitado. */
    private function prefixoCpf(string $digitos): string
    {
        return $this->aplicarMascara($digitos, [3, 3, 3, 2], ['.', '.', '-']);
    }

    /**
     * @param  array<int>  $grupos
     * @param  array<string>  $separadores
     */
    private function aplicarMascara(string $digitos, array $grupos, array $separadores): string
    {
        $saida = '';
        $pos = 0;

        foreach ($grupos as $i => $tamanho) {
            $pedaco = substr($digitos, $pos, $tamanho);
            if ($pedaco === '' || $pedaco === false) {
                break;
            }

            // Separador só entra quando o grupo anterior fechou — senão o prefixo ficaria
            // "16." com o usuário tendo digitado só "16", e o LIKE não casaria nada.
            if ($i > 0) {
                $saida .= $separadores[$i - 1];
            }

            $saida .= $pedaco;
            $pos += $tamanho;

            if (strlen($pedaco) < $tamanho) {
                break;
            }
        }

        return $saida;
    }
}
