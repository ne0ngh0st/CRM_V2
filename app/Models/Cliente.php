<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $fillable = [
        'cod_cliente',
        'loja',
        'cnpj',
        'razao_social',
        'nome_fantasia',
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
