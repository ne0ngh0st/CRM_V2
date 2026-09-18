<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liga uma conta estratégica a clientes do CRM: por grupo do TOTVS (`cod_grupo`) ou por
 * código de cliente avulso. Quem transforma isso em "os clientes da conta" é
 * `App\Services\VisaoDiretor\ClientesDaConta` — em nenhum outro lugar.
 */
class ContaEstrategicaVinculo extends Model
{
    public const TIPO_GRUPO = 'grupo';
    public const TIPO_CLIENTE = 'cliente';
    public const TIPOS = [self::TIPO_GRUPO, self::TIPO_CLIENTE];

    public const ORIGEM_SUGESTAO = 'sugestao';
    public const ORIGEM_MANUAL = 'manual';

    /**
     * Grupos que não podem virar vínculo. `9998` é CLIENTES DIVERSOS, o balde de "sem
     * grupo real" do TOTVS: sozinho concentra ~30% da base, e ligá-lo a uma conta faria
     * uma rede de 100 lojas aparecer com 27 mil.
     */
    public const GRUPOS_PROIBIDOS = ['9998'];

    protected $table = 'conta_estrategica_vinculos';

    protected $fillable = ['conta_id', 'tipo', 'codigo', 'origem'];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaEstrategica::class, 'conta_id');
    }
}
