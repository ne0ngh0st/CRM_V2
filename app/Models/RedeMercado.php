<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma das maiores redes do mercado (página Visão Diretor → Maiores redes). Vem do JSON da
 * planilha da diretoria; quem escreve é só `App\Services\VisaoDiretor\Redes\MaioresRedes`.
 *
 * ⚠️ Os contadores (clientes, ativos, vendedor, última compra, status) são DERIVADOS dos
 * vínculos e ficam fora do `$fillable` de propósito: só `MaioresRedes::recalcular()` grava.
 * O CNPJ da rede (`cnpj*`) também: só `MaioresRedes::gravarCnpjs()` e `informarCnpj()`.
 */
class RedeMercado extends Model
{
    public const STATUS_ATIVO = 'ativo';

    public const STATUS_A_TRABALHAR = 'a_trabalhar';

    protected $table = 'redes_mercado';

    protected $fillable = [
        'categoria', 'posicao', 'nome', 'apelidos', 'uf', 'lojas', 'data_base', 'qualidade', 'fonte', 'linha_original', 'ativa',
    ];

    protected function casts(): array
    {
        return [
            'apelidos' => 'array',
            'ativa' => 'boolean',
            'ultima_compra' => 'date',
        ];
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(RedeMercadoVinculo::class, 'rede_id');
    }
}
