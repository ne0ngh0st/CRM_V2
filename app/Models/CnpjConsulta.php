<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cartão CNPJ já normalizado (ver `CartaoCnpjService`). `dados` guarda o NOSSO
 * formato, nunca o payload cru do provedor — assim trocar de API não invalida o que
 * já está gravado nem obriga a tela a conhecer três formatos.
 */
class CnpjConsulta extends Model
{
    protected $table = 'cnpj_consultas';

    protected $fillable = ['cnpj', 'situacao', 'dados', 'fonte', 'consultado_em'];

    protected function casts(): array
    {
        return [
            'dados' => 'array',
            'consultado_em' => 'datetime',
        ];
    }
}
