<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liga uma rede do mercado a um cliente (`cod_cliente`, todas as filiais) ou a um lead.
 *
 * - `nivel` diz como: `nome`, `grupo`, `produto`, `mesma_empresa` (o motor, `VinculoDeRedes`) ou
 *   `manual` (a tela). A reconstrução refaz os do motor e NUNCA toca `manual` nem `recusado`.
 * - `recusado` = alguém tirou pela tela: o motor não liga de novo este código a esta rede.
 * - A coluna gerada `dono` (migration) impede o mesmo cliente vivo em duas redes.
 */
class RedeMercadoVinculo extends Model
{
    public const CLIENTE = 'cliente';

    public const LEAD = 'lead';

    public const MANUAL = 'manual';

    protected $table = 'redes_mercado_vinculos';

    protected $fillable = ['rede_id', 'tipo', 'codigo', 'nivel', 'recusado', 'user_id'];

    protected function casts(): array
    {
        return ['recusado' => 'boolean'];
    }

    public function rede(): BelongsTo
    {
        return $this->belongsTo(RedeMercado::class, 'rede_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
