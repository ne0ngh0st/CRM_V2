<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SegmentoVendedor extends Model
{
    protected $table = 'segmentos_vendedor';

    protected $fillable = [
        'cod_vendedor',
        'segmento',
        'curva_abc',
    ];
}
