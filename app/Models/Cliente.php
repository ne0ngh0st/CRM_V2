<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    /**
     * Prefixos de `loja` que são ENDEREÇO DE ENTREGA, não filial comercial.
     *
     * ✅ CONFIRMADO PELO ADRIANO (TOTVS) em 2026-09-11: `E` e `X` são endereço de
     * entrega mesmo. Antes disto era inferência nossa a partir dos dados — as 26.828
     * linhas com estes prefixos têm CNPJ idêntico ao da matriz e 98% nunca compraram,
     * porque a compra é lançada na loja comercial. Caso extremo real: a AUTOPASS tem 220
     * linhas na carteira da Sthefany, sendo 2 filiais (CNPJ /0001-40 e /0025-18) e 218
     * pontos de entrega, um por estação de metrô.
     *
     * ⚠️ Prefixo NOVO que o TOTVS venha a criar cai aqui como filial comercial, e nada
     * acusa — a contagem simplesmente para de separar. Se aparecer prefixo estranho na
     * base, é esta lista que precisa saber dele.
     */
    public const PREFIXOS_ENTREGA = ['E', 'X'];

    protected $fillable = [
        'cod_cliente',
        'loja',
        'cnpj',
        'razao_social',
        'nome_fantasia',
        'endereco',
        'municipio',
        'cod_vendedor',
        'cod_segmento',
        'cod_grupo',
        'estado',
        'cep',
        'telefone',
        'email',
        'data_ultima_compra',
        /*
         * ⚠️ `data_ultimo_contato`/`canal_ultimo_contato` NÃO entram no fillable de
         * propósito. São valor desnormalizado com dono único
         * (`UltimoContatoSincronizador`); deixá-las preenchíveis por `create()`/
         * `update()` é justamente como um import ou um seeder acabaria gravando um
         * valor que não veio de `ligacoes` — e aí a coluna passa a mentir sem erro.
         *
         * Mesmo caso de `cod_municipio` (código IBGE derivado de `municipio` + `estado`):
         * o dono único é o `MunicipioSincronizador`.
         */
    ];

    protected function casts(): array
    {
        return [
            'data_ultima_compra' => 'date',
            // datetime, não date: dois contatos no mesmo dia precisam ser distinguíveis.
            'data_ultimo_contato' => 'datetime',
        ];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    public function motivosInatividade(): HasMany
    {
        return $this->hasMany(CarteiraMotivoInatividade::class);
    }
}
