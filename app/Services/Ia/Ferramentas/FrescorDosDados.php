<?php

namespace App\Services\Ia\Ferramentas;

use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Totvs\FrescorDoDado;

/**
 * Até quando os dados do TOTVS estão carregados — a mesma pill do Painel.
 *
 * É a primeira coisa a conferir quando alguém diz "o número está errado": metade das
 * vezes o número está certo e o dado é que é de ontem.
 */
final class FrescorDosDados implements Ferramenta
{
    private const ROTULOS = [
        'atualizado' => 'atualizado',
        'atencao' => 'com atraso de 2 dias úteis',
        'desatualizado' => 'desatualizado (mais de 2 dias úteis)',
        'sem_dado' => 'sem dado',
    ];

    public function __construct(private readonly FrescorDoDado $frescor) {}

    public function nome(): string
    {
        return 'frescor_dos_dados';
    }

    public function rotuloProgresso(): string
    {
        return 'Conferindo a última atualização dos dados…';
    }

    public function descricao(): string
    {
        return 'Data do faturamento e do pedido mais recentes carregados do TOTVS e se estão em '
            .'dia. Use quando o usuário achar que um número está errado, desatualizado ou que '
            .'um pedido/nota "não apareceu".';
    }

    public function esquema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => [], 'additionalProperties' => false];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        return [
            'dominios' => array_map(fn (array $d) => [
                'dado' => $d['dominio'],
                'mais_recente' => $d['data'] ? date('d/m/Y', strtotime($d['data'])) : null,
                'situacao' => self::ROTULOS[$d['status']] ?? $d['status'],
            ], $this->frescor->porDominio()),
            'como_funciona' => 'Os relatórios do TOTVS são carregados ao longo do dia útil. Faturamento '
                .'e venda nas metas contam até o último dia fechado (ontem; na segunda, sexta).',
        ];
    }
}
