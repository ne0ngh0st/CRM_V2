<?php

namespace App\Services\Solicitacoes;

use App\Models\SolicitacaoEtiqueta;
use App\Services\Cadastros\SolicitacaoTituloResolver;

/**
 * Traduz uma solicitação de etiqueta pros rótulos e blocos do PDF — mesmo tratamento
 * do `BobinaPdfPresenter`, cópia fiel do legado
 * (`includes/pdf/solicitacao_etiqueta_pdf.php`, funções `etiqueta_mapear*` +
 * `gerarPdfSolicitacaoEtiqueta`). Se algum rótulo mudar lá, mudar aqui junto.
 */
class EtiquetaPdfPresenter
{
    private const PERSONALIZACAO = [
        'impresso' => 'Impresso',
        'sem_impressao' => 'Sem impressão',
    ];

    private const UNIDADE_VENDA = [
        'caixa' => 'Caixa',
        'unidade' => 'Unidade',
        'pacote_manual' => 'Pacote (manual)',
    ];

    /** Imagem + rótulo por sentido — igual `etiqueta_inserirImagemSaidaRolo()` do legado. */
    private const SAIDA_ROLO = [
        'f1' => ['F1.png', 'F1 - Saída pelo pé / base'],
        'f2' => ['F2.png', 'F2 - Saída pelo topo'],
        'f3' => ['F3.png', 'F3 - Saída pelo lado esquerdo'],
        'f4' => ['F4.png', 'F4 - Saída pelo lado direito'],
    ];

    public function __construct(
        private readonly SolicitacaoTituloResolver $tituloResolver,
    ) {}

    /** @return array<string, mixed> dados prontos pra view */
    public function montar(SolicitacaoEtiqueta $etiqueta): array
    {
        [$statusRotulo, $statusCor] = SolicitacaoFormatador::statusRotuloCor($etiqueta->status);

        return [
            'etiqueta' => $etiqueta,
            'statusRotulo' => $statusRotulo,
            'statusCor' => $statusCor,
            'emitidoEm' => now()->format('d/m/Y H:i'),
            'tituloDestaque' => $this->tituloDestaque($etiqueta),
            'logoPath' => SolicitacaoFormatador::logoPath(),
            'resumo' => [
                'Status' => $statusRotulo,
                'Data de criação' => SolicitacaoFormatador::data($etiqueta->created_at),
                'Solicitante' => $etiqueta->solicitante_nome ?: '-',
                'Código vendedor' => $etiqueta->cod_vendedor ?: '-',
            ],
            'comerciais' => [
                'Nomenclatura' => $etiqueta->nomenclatura ?: '-',
                'Possui estoque de segurança?' => SolicitacaoFormatador::simNao($etiqueta->estoque_seguranca_sn),
                'Estoque de segurança' => SolicitacaoFormatador::numero($etiqueta->estoque_seguranca),
            ],
            'tecnicas' => [
                'Personalização' => SolicitacaoFormatador::mapear(self::PERSONALIZACAO, $etiqueta->personalizacao),
                'Unidade de venda' => SolicitacaoFormatador::mapear(self::UNIDADE_VENDA, $etiqueta->unidade_venda),
                'Quantidade por caixa' => SolicitacaoFormatador::numero($etiqueta->quantidade_caixa),
                'Metragem total (m)' => SolicitacaoFormatador::numero($etiqueta->metragem),
                'Medidas (L x A)' => $etiqueta->medidas ?: '-',
                'Aplicação' => $etiqueta->aplicacao ?: '-',
                'Tipo de adesivo' => $etiqueta->tipo_adesivo ?: '-',
                'Diâmetro do tubete' => $etiqueta->diametro_tubete ?: '-',
            ],
            'envio' => [
                'Enviado por' => $etiqueta->enviadoPor?->display_name ?: ($etiqueta->enviadoPor?->name ?: '-'),
                'Data do envio' => SolicitacaoFormatador::data($etiqueta->enviado_em),
            ],
            'observacoes' => trim((string) $etiqueta->observacoes),
            'saidaRolo' => $this->saidaRolo($etiqueta->saida_rolo),
        ];
    }

    /** @return array{imagemPath: string, rotulo: string}|null */
    private function saidaRolo(?string $valor): ?array
    {
        $chave = mb_strtolower(trim((string) $valor));
        if (! isset(self::SAIDA_ROLO[$chave])) {
            return null;
        }

        [$arquivo, $rotulo] = self::SAIDA_ROLO[$chave];
        $caminho = public_path('images/'.$arquivo);

        if (! file_exists($caminho)) {
            return null;
        }

        return ['imagemPath' => $caminho, 'rotulo' => $rotulo];
    }

    /** Mesma ideia do `tituloDestaque()` da bobina: recalcula na hora, cai pro gravado se vazio. */
    private function tituloDestaque(SolicitacaoEtiqueta $etiqueta): string
    {
        $gerado = $this->tituloResolver->etiqueta(
            (string) $etiqueta->nomenclatura,
            $etiqueta->medidas,
            $etiqueta->tipo_adesivo,
            $etiqueta->metragem,
        );

        if ($gerado !== '') {
            return $gerado;
        }

        return $etiqueta->titulo_padronizado ?: 'Solicitação de etiqueta';
    }
}
