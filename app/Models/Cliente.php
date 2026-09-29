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

    /**
     * O que o campo de busca da Carteira casa.
     *
     * ⚠️ Mora aqui, e não no CarteiraController, porque os Leads também perguntam
     * "quantos clientes batem com esta busca?" para o aviso cruzado (`BuscaCruzada`).
     * Com a regra copiada, o aviso e a tela que ele abre diriam números diferentes.
     * Regra de ouro nº 8.
     *
     * Colunas qualificadas porque a listagem da Carteira faz join com outras tabelas.
     */
    public function scopeBusca($query, string $termo)
    {
        return $query->where(function ($q) use ($termo) {
            $q->where('clientes.razao_social', 'like', "%{$termo}%")
                ->orWhere('clientes.nome_fantasia', 'like', "%{$termo}%")
                ->orWhere('clientes.cnpj', 'like', "%{$termo}%")
                ->orWhere('clientes.cod_cliente', 'like', "%{$termo}%");
        });
    }
}
