<?php

namespace App\Services\Solicitacoes;

use App\Models\SolicitacaoBobina;

/**
 * Traduz uma solicitação de bobina pros rótulos e blocos do PDF.
 *
 * Os textos são cópia fiel do legado (`includes/pdf/solicitacao_bobina_pdf.php`,
 * funções `mapear*`) porque o Tony pediu o PDF "igualzinho o legado" — quem recebe a
 * ficha (Cadastro/PCP) está acostumado com essa nomenclatura, inclusive os NCMs.
 * Se algum rótulo mudar lá, mudar aqui junto.
 */
class BobinaPdfPresenter
{
    private const PERSONALIZACAO = [
        'personalizada' => 'Personalizada',
        'sem_impressao' => 'Sem impressão',
    ];

    private const UNIDADE_VENDA = [
        'caixa' => 'Caixa',
        'unidade' => 'Unidade',
    ];

    private const PAPEL = [
        'termicco' => 'Térmico',
        'termoscript' => 'Termoscript',
        'kpr' => 'KPR',
        'termobank' => 'Termobank',
    ];

    private const IMPRESSAO = [
        'verso' => 'Verso',
        'frente_lado_termico' => 'Frente (lado térmico)',
        'frente_verso' => 'Frente e Verso',
    ];

    private const REBOBINAMENTO = [
        'lado_termico_fora' => 'Lado térmico para fora',
        'lado_termico_dentro' => 'Lado térmico para dentro',
    ];

    /** O NCM faz parte do rótulo no legado — é o que o Cadastro usa pra abrir o item. */
    private const NF_TIPO = [
        'venda' => 'Venda – NCM 48119010',
        'servico' => 'Serviço – NCM 49111090',
    ];

    /** [rótulo, cor do selo] — mesmas cores do `definirStatus()` do legado. */
    private const STATUS = [
        'pendente' => ['Pendente', '#D97706'],
        'enviado' => ['Enviado', '#16803D'],
        'enviada' => ['Enviada', '#16803D'],
        'aprovado' => ['Aprovado', '#16803D'],
        'aprovada' => ['Aprovada', '#16803D'],
        'reprovado' => ['Reprovado', '#B91C1C'],
        'reprovada' => ['Reprovada', '#B91C1C'],
        'cancelado' => ['Cancelado', '#64748B'],
        'cancelada' => ['Cancelada', '#64748B'],
    ];

    /** @return array<string, mixed> dados prontos pra view */
    public function montar(SolicitacaoBobina $bobina): array
    {
        [$statusRotulo, $statusCor] = self::STATUS[mb_strtolower(trim((string) $bobina->status))]
            ?? [ucfirst((string) $bobina->status), '#64748B'];

        return [
            'bobina' => $bobina,
            'statusRotulo' => $statusRotulo,
            'statusCor' => $statusCor,
            // O título padronizado TOTVS já é calculado e gravado no cadastro.
            'tituloDestaque' => $bobina->titulo_padronizado ?: ($bobina->nomenclatura ?: 'Solicitação de bobina'),
            'resumo' => [
                'Status' => $statusRotulo,
                'Data de criação' => $this->data($bobina->created_at),
                'Solicitante' => $bobina->solicitante_nome ?: '-',
                'Código vendedor' => $bobina->cod_vendedor ?: '-',
            ],
            'comerciais' => [
                'Nomenclatura' => $bobina->nomenclatura ?: '-',
                'NF pedido tipo' => $this->mapear(self::NF_TIPO, $bobina->nf_pedido_tipo),
                'Possui estoque de segurança?' => $this->simNao($bobina->estoque_seguranca_sn),
                'Estoque de segurança' => $this->numero($bobina->estoque_seguranca),
            ],
            'tecnicas' => [
                'Personalização' => $this->mapear(self::PERSONALIZACAO, $bobina->personalizacao),
                'Unidade de venda' => $this->mapear(self::UNIDADE_VENDA, $bobina->unidade_venda),
                'Quantidade por caixa' => $this->numero($bobina->quantidade_caixa),
                'Papel' => $this->mapear(self::PAPEL, $bobina->papel, maiusculoSeDesconhecido: true),
                'Gramatura' => $bobina->gramatura ? $bobina->gramatura.' g/m²' : '-',
                'Largura (mm)' => $this->numero($bobina->largura),
                'Metragem (m)' => $this->numero($bobina->metragem),
                'Impressão' => $this->mapear(self::IMPRESSAO, $bobina->impressao),
                'Rebobinamento' => $this->mapear(self::REBOBINAMENTO, $bobina->rebobinamento),
                'Diâmetro do tubete (mm)' => $this->numero($bobina->diametro_tubete),
            ],
            'envio' => [
                'Enviado por' => $bobina->enviadoPor?->display_name ?: ($bobina->enviadoPor?->name ?: '-'),
                'Data do envio' => $this->data($bobina->enviado_em),
            ],
        ];
    }

    /** @param array<string, string> $mapa */
    private function mapear(array $mapa, ?string $valor, bool $maiusculoSeDesconhecido = false): string
    {
        $chave = mb_strtolower(trim((string) $valor));

        if ($chave === '') {
            return '-';
        }

        return $mapa[$chave] ?? ($maiusculoSeDesconhecido ? mb_strtoupper($chave) : '-');
    }

    private function simNao(?string $valor): string
    {
        return match (mb_strtolower(trim((string) $valor))) {
            'sim' => 'Sim',
            'nao', 'não' => 'Não',
            default => '-',
        };
    }

    /** Inteiro fica sem casas; decimal perde os zeros à direita — igual ao legado. */
    private function numero(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        if (! is_numeric($valor)) {
            return (string) $valor;
        }

        $numero = (float) $valor;

        if ($numero === (float) (int) $numero) {
            return (string) (int) $numero;
        }

        return rtrim(rtrim(number_format($numero, 2, ',', ''), '0'), ',');
    }

    private function data(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        return $valor instanceof \DateTimeInterface
            ? $valor->format('d/m/Y H:i')
            : (($ts = strtotime((string) $valor)) ? date('d/m/Y H:i', $ts) : '-');
    }
}
