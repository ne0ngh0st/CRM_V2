<?php

namespace App\Models;

use App\Services\Totvs\Normalizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orcamento extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        /*
         * Vínculo real com `clientes`, necessário para resolver o `clientId` do Portal
         * (o de-para é por cod_cliente + loja). ⚠️ As colunas `portal_*` NÃO entram no
         * fillable de propósito: quem escreve nelas é o GeradorDePedidoNoPortal e mais
         * ninguém — mesmo raciocínio de `clientes.data_ultimo_contato`.
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
        'tipo_frete',
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
            'portal_enviado_em' => 'datetime',
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

    private function clientePeloCnpj(): ?Cliente
    {
        $mascarado = Normalizador::documento($this->cliente_cnpj);

        return $mascarado === null
            ? null
            : Cliente::query()->where('cnpj', $mascarado)->first();
    }
}
