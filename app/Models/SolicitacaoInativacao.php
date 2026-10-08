<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de inativação enviado ao Cadastro — ver `PedidoDeInativacao`. */
class SolicitacaoInativacao extends Model
{
    protected $table = 'solicitacoes_inativacao';

    protected $fillable = ['cliente_id', 'cnpj', 'situacao_receita', 'solicitado_por'];

    protected $casts = ['enviado_em' => 'datetime'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
