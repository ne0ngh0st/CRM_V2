<?php

namespace App\Models;

use App\Services\Carteira\UltimoContatoSincronizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ligacao extends Model
{
    /**
     * Canais de contato registráveis — a lista canônica do sistema.
     *
     * ⚠️ ESPELHA O ENUM DA COLUNA `ligacoes.tipo_contato`. É o que a validação dos
     * controllers usa: sem ela, um `tipo` vindo do front cairia direto no enum do
     * MySQL, que fora do modo estrito grava string vazia em SILÊNCIO — o contato
     * entraria sem canal e a métrica por canal passaria a mentir.
     *
     * Canal novo entra em TRÊS lugares: migration do enum, esta constante e
     * `resources/js/constants/contatos.js` (rótulo exibido).
     *
     * @var list<string>
     */
    public const TIPOS_CONTATO = ['telefonica', 'whatsapp', 'email', 'presencial'];

    protected $table = 'ligacoes';

    protected $fillable = [
        'usuario_id',
        'cliente_id',
        'lead_id',
        'cliente_nome',
        'tipo_contato',
        'status',
        'data_ligacao',
    ];

    protected function casts(): array
    {
        return [
            'data_ligacao' => 'datetime',
        ];
    }

    /**
     * Acrescenta um `SUM(tipo_contato = ?)` por canal a uma agregação já montada.
     *
     * ⚠️ São colunas na MESMA query, não queries novas: a quebra por canal custa
     * zero ida a mais ao banco, e o plano não muda — os SUMs são avaliados sobre as
     * linhas que o índice já ia ler de qualquer jeito.
     *
     * Mora aqui, e não em cada chamador, porque Painel e Visão do Gestor precisam
     * exatamente da mesma quebra (Regra de ouro nº 8). O laço percorre `TIPOS_CONTATO`,
     * então canal novo na constante aparece sozinho nas duas telas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>|\Illuminate\Database\Query\Builder  $query
     */
    public static function somarPorCanal($query): void
    {
        foreach (self::TIPOS_CONTATO as $canal) {
            $query->selectRaw("SUM(tipo_contato = ?) as canal_{$canal}", [$canal]);
        }
    }

    /**
     * Lê de volta as colunas que `somarPorCanal()` acrescentou, sempre com todos os
     * canais presentes — canal sem nenhum registro vem 0, e não ausente, para o front
     * não ter que tratar buraco no objeto.
     *
     * @return array<string, int>
     */
    public static function lerPorCanal(?object $linha): array
    {
        $porCanal = [];
        foreach (self::TIPOS_CONTATO as $canal) {
            $porCanal[$canal] = (int) ($linha->{'canal_'.$canal} ?? 0);
        }

        return $porCanal;
    }

    /**
     * Mantém `clientes.data_ultimo_contato` sincronizado.
     *
     * ⚠️ É HOOK DE MODEL, E NÃO CÓDIGO NOS CONTROLLERS, DE PROPÓSITO — ao contrário
     * das notificações deste projeto, que ficam no controller que já muda o estado.
     * A diferença: notificação esquecida é notificação a menos; coluna desnormalizada
     * esquecida é DADO ERRADO na tela, silencioso, e que a ordenação usa. Hoje são
     * dois pontos que criam contato (Carteira e Leads) e qualquer tela nova seria um
     * terceiro. Aqui é impossível esquecer.
     *
     * Só `created`: contato é inserido e nunca editado no sistema. Se um dia passar a
     * ser, este é o lugar de tratar (e a reconstrução conserta o passado).
     */
    protected static function booted(): void
    {
        static::created(function (self $ligacao) {
            app(UltimoContatoSincronizador::class)->aoRegistrar($ligacao);
        });
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
