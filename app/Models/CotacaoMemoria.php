<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A decisão humana que vale para cada texto normalizado — é o que o motor consulta antes
 * de qualquer comparação (vira APRENDIDO / SEM_MATCH_CONFIRMADO).
 *
 * ⚠️ A chave vem do motor Python. Ver a migration `create_cotacoes_tables`.
 */
class CotacaoMemoria extends Model
{
    protected $table = 'cotacao_memoria';

    protected $primaryKey = 'texto_normalizado';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'texto_normalizado', 'descricao_cliente', 'decisao', 'codigo', 'descricao_nossa',
        'user_id', 'quem', 'decisao_id', 'atualizado_em',
    ];

    protected function casts(): array
    {
        return ['atualizado_em' => 'datetime'];
    }
}
