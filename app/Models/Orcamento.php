<?php

namespace App\Models;

use App\Services\Totvs\Normalizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orcamento extends Model
{
    /**
     * Tipos de venda aceitos (viram o `invoiceType` do Portal e, no Protheus, a TES).
     * Rótulos de tela em `resources/js/constants/orcamentos.js` (ROTULOS_TIPO_VENDA);
     * o de-para para o Portal em PortalPedidoPayload::invoiceType().
     */
    public const TIPOS_VENDA = ['consumo', 'revenda', 'servico'];

    /**
     * Aceite do CLIENTE — o vendedor registra. Nulo = aguardando resposta.
     *
     * ⚠️ Não confundir com `status_gestor`, que é a aprovação de MARGEM (desconto). São
     * independentes: o cliente pode aceitar com a margem ainda na fila. Para virar pedido
     * valem as duas (ver `bloqueioDoPedido()`).
     */
    public const RESPOSTAS_CLIENTE = ['aprovado', 'recusado'];

    /**
     * Motivos de recusa do cliente. Lista fixa (dá para agregar no BI) + "Outros", que
     * exige texto. Rótulos saem prontos do servidor; o front não tem cópia deste mapa.
     */
    public const MOTIVOS_RECUSA_CLIENTE = [
        'preco' => 'Preço',
        'prazo' => 'Prazo de entrega',
        'concorrente' => 'Fechou com concorrente',
        'desistiu' => 'Cliente desistiu da compra',
        'sem_retorno' => 'Sem retorno do cliente',
        'outros' => 'Outros',
    ];

    /**
     * Etapa do orçamento no caminho até o pedido — o funil da tela de Orçamentos.
     *
     * 🚨 Cada orçamento cai em EXATAMENTE UMA etapa (ver `sqlEtapa()`), e é isso que faz o
     * funil somar o total. A primeira versão eram tiles soltos por dimensão (margem,
     * cliente, pedido) que se sobrepunham e o Tony recusou: "mais confunde".
     * A ordem aqui é a ordem da tela.
     */
    public const ETAPAS = [
        'aguardando_margem' => 'Aguardando margem',
        'aguardando_cliente' => 'Aguardando cliente',
        'pronto' => 'Pronto p/ pedido',
        'pedido' => 'Virou pedido',
        'perdido' => 'Perdido',
    ];

    protected $fillable = [
        'user_id',
        'lead_id',
        /*
         * Vínculo real com `clientes`, necessário para resolver o `clientId` do Portal
         * (o de-para é por cod_cliente + loja). ⚠️ As colunas `portal_*` NÃO entram no
         * fillable de propósito: quem escreve nelas é o GeradorDePedidoNoPortal e mais
         * ninguém — mesmo raciocínio de `clientes.data_ultimo_contato`. As de
         * `resposta_cliente*` também ficam de fora: quem escreve é
         * `registrarRespostaCliente()` / `limparRespostaCliente()`.
         */
        'cliente_id',
        'cliente_nome',
        'cliente_cnpj',
        // Cópia do endereço no momento da emissão — ver o docblock da migration
        // 2026_09_11_100000 para por que é cópia e não join.
        'cliente_endereco',
        'cliente_municipio',
        'cliente_estado',
        'cliente_cep',
        'cliente_contato',
        'forma_pagamento',
        'condicao_pagamento_codigo',
        'tipo_frete',
        'tipo_venda',
        'tipo_produto_servico',
        'valor_total',
        'data_validade',
        'desconto_pct_max',
        'nivel_aprovacao',
        'status_gestor',
        'aprovado_por_id',
        'aprovado_em',
        'motivo_rejeicao',
        'observacoes',
        'variacao_producao_personalizado',
        'prazo_producao',
        'garantia_imagem',
        'texto_importante',
    ];

    protected function casts(): array
    {
        return [
            'data_validade' => 'date',
            'aprovado_em' => 'datetime',
            'valor_total' => 'decimal:2',
            'desconto_pct_max' => 'decimal:2',
            'portal_payload' => 'array',
            'portal_resposta' => 'array',
            'portal_enviado_em' => 'datetime',
            'resposta_cliente_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por_id');
    }

    public function respostaClientePor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resposta_cliente_por_id');
    }

    /** De qual lead este orçamento saiu, quando saiu de um. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Cliente do TOTVS, quando o orçamento é de cliente já cadastrado.
     *
     * ⚠️ Nulo em dois casos legítimos: orçamento de LEAD (prospect, que o Portal
     * recusa com 409) e os 1.864 históricos importados do legado, que só têm nome e
     * CNPJ em texto. Nenhum dos dois vira pedido.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Já foi transformado em pedido no Portal? */
    public function foiEnviadoAoPortal(): bool
    {
        return $this->portal_pedido_id !== null;
    }

    /**
     * Existe uma tentativa de envio cujo resultado NÃO se sabe (na fila, ou o Portal não
     * respondeu) — ela pode ter criado o pedido. Enquanto for verdade, o próximo clique
     * reenvia o corpo congelado com a mesma chave, sem data nova. Recusa do Portal apaga
     * a chave (erro lá = nada gravado), então não cai aqui.
     */
    public function temEnvioIncertoAoPortal(): bool
    {
        return ! $this->foiEnviadoAoPortal()
            && filled($this->portal_idempotency_key)
            && is_array($this->portal_payload);
    }

    /**
     * Por que este orçamento ainda NÃO pode virar pedido — null quando pode.
     *
     * É a frase que a tela mostra no botão desabilitado e a regra que a rota aplica: as
     * duas leem daqui, para não dizerem coisas diferentes.
     */
    public function bloqueioDoPedido(): ?string
    {
        if ($this->status_gestor === 'rejeitado') {
            return 'A margem foi rejeitada.';
        }

        if ($this->status_gestor !== 'aprovado') {
            return 'Falta a aprovação de margem.';
        }

        if ($this->resposta_cliente !== 'aprovado') {
            return 'Falta registrar o aceite do cliente.';
        }

        return null;
    }

    /**
     * A etapa como expressão SQL — a ÚNICA definição dela: conta o funil e filtra a lista.
     *
     * ⚠️ A ordem dos WHEN é a regra de precedência, e é o que torna as etapas exclusivas:
     * pedido vence tudo (virou pedido, acabou); depois perdido (margem rejeitada OU cliente
     * recusou — um "não" basta); depois a margem pendente (aceite antecipado do cliente
     * continua esperando a margem); depois o aceite. Reordenar muda os números sem erro.
     */
    public static function sqlEtapa(): string
    {
        return "CASE
            WHEN portal_pedido_id IS NOT NULL THEN 'pedido'
            WHEN status_gestor = 'rejeitado' OR resposta_cliente = 'recusado' THEN 'perdido'
            WHEN status_gestor = 'pendente' THEN 'aguardando_margem'
            WHEN resposta_cliente IS NULL THEN 'aguardando_cliente'
            ELSE 'pronto'
        END";
    }

    /** "Aceitou", "Recusou: Preço", "Recusou: <texto do Outros>" — null quando aguardando. */
    public function rotuloRespostaCliente(): ?string
    {
        if ($this->resposta_cliente === 'aprovado') {
            return 'Aceitou';
        }

        if ($this->resposta_cliente !== 'recusado') {
            return null;
        }

        $motivo = $this->motivo_recusa_cliente === 'outros' && filled($this->motivo_recusa_cliente_texto)
            ? $this->motivo_recusa_cliente_texto
            : (self::MOTIVOS_RECUSA_CLIENTE[$this->motivo_recusa_cliente] ?? 'sem motivo');

        return 'Recusou: '.$motivo;
    }

    public function registrarRespostaCliente(User $por, string $resposta, ?string $motivo, ?string $texto): void
    {
        $recusou = $resposta === 'recusado';

        $this->forceFill([
            'resposta_cliente' => $resposta,
            'motivo_recusa_cliente' => $recusou ? $motivo : null,
            'motivo_recusa_cliente_texto' => $recusou && $motivo === 'outros' ? $texto : null,
            'resposta_cliente_por_id' => $por->id,
            'resposta_cliente_em' => now(),
        ])->save();
    }

    /** Volta a "aguardando". Usado quando o orçamento é editado: o cliente aceitou outro documento. */
    public function limparRespostaCliente(): void
    {
        $this->forceFill([
            'resposta_cliente' => null,
            'motivo_recusa_cliente' => null,
            'motivo_recusa_cliente_texto' => null,
            'resposta_cliente_por_id' => null,
            'resposta_cliente_em' => null,
        ])->save();
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class);
    }

    public function usaIpi(): bool
    {
        return $this->tipo_produto_servico === 'produto';
    }

    /**
     * O endereço que o DOCUMENTO deve mostrar — tela e PDF leem daqui, e só daqui.
     *
     * Sugestão do Vagner Sabelli (10/09/2026): "constar no orçamento o endereço
     * completo correspondente ao cnpj orçado".
     *
     * Dois regimes, e é de propósito que convivam:
     *
     *   1. Orçamento com cópia gravada → devolve a cópia. É o retrato do que foi
     *      enviado ao cliente, e não muda se a empresa mudar de endereço depois.
     *   2. Orçamento anterior a esta feature (não tem cópia) → cai para o endereço
     *      ATUAL do cliente. Melhor que um traço: o documento é reimpresso hoje e o
     *      endereço de hoje é a melhor informação disponível. Nada é gravado aqui —
     *      o snapshot só nasce quando alguém salva o orçamento.
     *
     * ⚠️ O fallback casa por CNPJ, não por `cliente_id`: medido em 11/09, os 2.127
     * orçamentos existentes têm `cliente_id` NULO (a coluna nasceu em 10/09, com o
     * Portal) e 2.120 deles têm CNPJ. Casar só por `cliente_id` atenderia zero
     * documentos históricos, que são exatamente os que precisam do fallback.
     *
     * ⚠️ E casa pelo CNPJ **remontado na máscara** (via Normalizador::documento), nunca
     * com REPLACE() na coluna: `clientes.cnpj` guarda mascarado (92.197 de 92.200), o
     * orçamento guarda só dígitos, e envolver a coluna numa função joga fora o índice
     * e varre as 92 mil linhas. Mesma armadilha documentada em BuscaTitularidade.
     *
     * ⚠️ Custo: UMA query, e só no caminho 2. Use em documento (1 orçamento por
     * página), nunca dentro de laço de listagem — ali viraria N+1.
     *
     * @return array{logradouro: ?string, municipio: ?string, estado: ?string, cep: ?string}
     */
    public function enderecoDoDocumento(): array
    {
        if ($this->cliente_endereco !== null || $this->cliente_municipio !== null) {
            return [
                'logradouro' => $this->cliente_endereco,
                'municipio' => $this->cliente_municipio,
                'estado' => $this->cliente_estado,
                'cep' => $this->cliente_cep,
            ];
        }

        $cliente = $this->cliente ?: $this->clientePeloCnpj();

        return [
            'logradouro' => $cliente?->endereco,
            'municipio' => $cliente?->municipio,
            'estado' => $cliente?->estado,
            'cep' => $cliente?->cep,
        ];
    }

    /**
     * Uma linha só, para quem vai imprimir: "RUA NOVE 420 — CONTAGEM/MG — CEP 32183020".
     *
     * ⚠️ A formatação mora aqui e não no Blade/Vue porque os dois precisam dela
     * idêntica: a folha na tela e o PDF são um par declarado (ver o topo de
     * pdf.blade.php), e formatação copiada é como eles divergem.
     */
    public function enderecoDoDocumentoEmLinha(): ?string
    {
        $e = $this->enderecoDoDocumento();

        $cidadeUf = collect([$e['municipio'], $e['estado']])->filter()->implode('/');
        $cep = filled($e['cep']) ? 'CEP '.$e['cep'] : null;

        $partes = collect([$e['logradouro'], $cidadeUf ?: null, $cep])->filter();

        return $partes->isEmpty() ? null : $partes->implode(' — ');
    }

    /**
     * O cliente daquele CNPJ — **só quando a resposta é inequívoca**.
     *
     * 🚨 CNPJ NÃO IDENTIFICA UM CLIENTE NESTA BASE. O grão de `clientes` é a filial
     * (`cod_cliente` + `loja`) justamente porque o mesmo CNPJ se repete entre filiais —
     * é a razão de a Regra de ouro nº 3 existir. Medido em produção (11/09/2026):
     *
     *     8.142 CNPJs aparecem em mais de um cliente
     *     7.518 deles com ENDEREÇOS diferentes entre si
     *     2.334 deles em MUNICÍPIOS diferentes
     *
     * Pegar o `first()` num desses carimbaria no orçamento o endereço de outra filial —
     * às vezes de outra cidade — num documento que vai para o cliente e que ninguém
     * tem como conferir. Endereço errado é pior que endereço ausente: o vendedor
     * confia no que está impresso, e o campo em branco ao menos se denuncia.
     *
     * Por isso: divergiu, devolve nada. O vendedor digita, e o que ele digitar vira a
     * cópia do documento.
     *
     * ⚠️ Isto vale só para o FALLBACK, que existe para o acervo histórico sem vínculo.
     * Orçamento novo não passa por aqui — ele guarda a cópia da linha exata que o
     * vendedor escolheu na busca, então filial repetida não é ambiguidade nenhuma.
     */
    private function clientePeloCnpj(): ?Cliente
    {
        $mascarado = Normalizador::documento($this->cliente_cnpj);

        if ($mascarado === null) {
            return null;
        }

        $candidatos = Cliente::query()
            ->where('cnpj', $mascarado)
            // Teto pequeno de propósito: não é paginação, é um guarda contra CNPJ
            // "coringa" do TOTVS (o 00.000.000/xxxx-xx chega a agrupar dezenas de
            // entregas). Passando de 2 já se sabe que é ambíguo.
            ->limit(25)
            ->get();

        if ($candidatos->isEmpty()) {
            return null;
        }

        $enderecosDistintos = $candidatos
            ->map(fn (Cliente $c) => implode('|', [$c->endereco, $c->municipio, $c->estado, $c->cep]))
            ->unique();

        return $enderecosDistintos->count() === 1 ? $candidatos->first() : null;
    }
}
