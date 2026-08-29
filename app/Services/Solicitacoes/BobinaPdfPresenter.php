<?php

namespace App\Services\Solicitacoes;

use App\Models\SolicitacaoBobina;
use App\Services\Cadastros\SolicitacaoTituloResolver;

/**
 * Traduz uma solicitação de bobina pros rótulos e blocos do PDF.
 *
 * Os textos e o recorte de campos são cópia fiel do legado
 * (`includes/pdf/solicitacao_bobina_pdf.php`, funções `mapear*` + `gerarPdfSolicitacaoBobina`)
 * porque o Tony pediu o PDF "igualzinho o legado" — quem recebe a ficha (Cadastro/PCP)
 * está acostumado com essa nomenclatura, inclusive os NCMs.
 * Se algum rótulo mudar lá, mudar aqui junto.
 *
 * ⚠️ O PDF do legado NÃO lista impressão/rebobinamento (esses vão na arte). Lista
 * "Uso obrigatório de tubete". Não reintroduzir aqueles dois campos aqui.
 *
 * Formatação genérica (número/data/sim-não/status/logo) mora em
 * `SolicitacaoFormatador`, reusada também pelo `EtiquetaPdfPresenter`.
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

    /** O NCM faz parte do rótulo no legado — é o que o Cadastro usa pra abrir o item. */
    private const NF_TIPO = [
        'venda' => 'Venda – NCM 48119010',
        'servico' => 'Serviço – NCM 49111090',
    ];

    public function __construct(
        private readonly SolicitacaoTituloResolver $tituloResolver,
    ) {}

    /** @return array<string, mixed> dados prontos pra view */
    public function montar(SolicitacaoBobina $bobina): array
    {
        [$statusRotulo, $statusCor] = SolicitacaoFormatador::statusRotuloCor($bobina->status);

        return [
            'bobina' => $bobina,
            'statusRotulo' => $statusRotulo,
            'statusCor' => $statusCor,
            'emitidoEm' => now()->format('d/m/Y H:i'),
            // O legado recalcula o título a cada PDF (`bobina_titulo_da_solicitacao`).
            'tituloDestaque' => $this->tituloDestaque($bobina),
            'logoPath' => SolicitacaoFormatador::logoPath(),
            'resumo' => [
                'Status' => $statusRotulo,
                'Data de criação' => SolicitacaoFormatador::data($bobina->created_at),
                'Solicitante' => $bobina->solicitante_nome ?: '-',
                'Código vendedor' => $bobina->cod_vendedor ?: '-',
            ],
            'comerciais' => [
                'Nomenclatura' => $bobina->nomenclatura ?: '-',
                'NF pedido tipo' => SolicitacaoFormatador::mapear(self::NF_TIPO, $bobina->nf_pedido_tipo),
                'Possui estoque de segurança?' => SolicitacaoFormatador::simNao($bobina->estoque_seguranca_sn),
                'Estoque de segurança' => SolicitacaoFormatador::numero($bobina->estoque_seguranca),
            ],
            'tecnicas' => [
                'Personalização' => SolicitacaoFormatador::mapear(self::PERSONALIZACAO, $bobina->personalizacao),
                'Unidade de venda' => SolicitacaoFormatador::mapear(self::UNIDADE_VENDA, $bobina->unidade_venda),
                'Quantidade por caixa' => SolicitacaoFormatador::numero($bobina->quantidade_caixa),
                'Papel' => SolicitacaoFormatador::mapear(self::PAPEL, $bobina->papel, maiusculoSeDesconhecido: true),
                'Gramatura' => $bobina->gramatura ? $bobina->gramatura.' g/m²' : '-',
                'Largura (mm)' => SolicitacaoFormatador::numero($bobina->largura),
                'Metragem (m)' => SolicitacaoFormatador::numero($bobina->metragem),
                'Uso obrigatório de tubete' => SolicitacaoFormatador::simNao($bobina->tubete_obrigatorio),
                'Diâmetro do tubete (mm)' => SolicitacaoFormatador::numero($bobina->diametro_tubete),
            ],
            'envio' => [
                'Enviado por' => $bobina->enviadoPor?->display_name ?: ($bobina->enviadoPor?->name ?: '-'),
                'Data do envio' => SolicitacaoFormatador::data($bobina->enviado_em),
            ],
            // O legado omite o bloco inteiro quando não tem texto (`secaoTexto` retorna cedo).
            'observacoes' => trim((string) $bobina->observacoes),
        ];
    }

    /**
     * Mesma regra de `bobina_titulo_da_solicitacao()`: gera na hora (BOBINA + tipo +
     * dimensão + nomenclatura) e só cai no título gravado se a geração vier vazia.
     */
    private function tituloDestaque(SolicitacaoBobina $bobina): string
    {
        $gerado = $this->tituloResolver->bobina(
            (string) $bobina->nomenclatura,
            $bobina->papel,
            $bobina->largura !== null ? (string) $bobina->largura : null,
            $bobina->metragem,
            $bobina->gramatura,
        );

        if ($gerado !== '') {
            return $gerado;
        }

        return $bobina->titulo_padronizado ?: 'Solicitação de bobina';
    }
}
