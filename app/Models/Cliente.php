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
        'estado',
        'cep',
        'telefone',
        'email',
        'data_ultima_compra',
    ];

    protected function casts(): array
    {
        return [
            'data_ultima_compra' => 'date',
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

    public function contatos(): HasMany
    {
        return $this->hasMany(ClienteContatado::class);
    }

    public function ocultacoes(): HasMany
    {
        return $this->hasMany(CarteiraClienteOculto::class);
    }
}
