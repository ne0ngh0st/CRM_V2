<?php

namespace Tests\Unit;

use App\Services\Totvs\LeitorRelatorio;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Trava o formato dos relatórios do TOTVS. Cada caso aqui saiu de uma peculiaridade
 * observada nos arquivos reais, não de imaginação — ver o cabeçalho do LeitorRelatorio.
 */
class LeitorRelatorioTotvsTest extends TestCase
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }

        parent::tearDown();
    }

    private function arquivo(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'rlt').'.csv';
        file_put_contents($caminho, $conteudo);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    public function test_pula_a_linha_de_titulo_do_relatorio(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "210 - CADASTRO DE CLIENTES.RLT;;;\n".
            "Codigo;Loja;Nome\n".
            "000001;0001;ACME\n"
        ));

        $this->assertSame('210 - CADASTRO DE CLIENTES.RLT', $leitor->titulo());
        $this->assertSame(['Codigo', 'Loja', 'Nome'], $leitor->cabecalho());
        $this->assertSame(
            [['Codigo' => '000001', 'Loja' => '0001', 'Nome' => 'ACME']],
            array_values(iterator_to_array($leitor->linhas()))
        );
    }

    /** O base_marco (leads) não tem linha de título — começa direto no cabeçalho. */
    public function test_arquivo_sem_titulo_usa_a_primeira_linha_como_cabecalho(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "FONTE;cnpj;RAZAO SOCIAL\n".
            "ABRAS;00000000000191;ACME LTDA\n"
        ));

        $this->assertNull($leitor->titulo());
        $this->assertSame(['FONTE', 'cnpj', 'RAZAO SOCIAL'], $leitor->cabecalho());
        $this->assertCount(1, iterator_to_array($leitor->linhas()));
    }

    /**
     * O 199 - ULTIMO FATURAMENTO traz `Descricao` duas vezes: a primeira é a descrição
     * do grupo de vendas, a segunda a do segmento. É delas que saem `grupos_cliente` e
     * `segmentos` — ler pelo nome sem desambiguar pega a coluna errada em silêncio.
     */
    public function test_coluna_repetida_ganha_sufixo_preservando_a_ordem(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "199 - ULTIMO FATURAMENTO CLIENTE.RLT;;;;\n".
            "Grp.Vendas;Descricao;Segmento 1;Descricao;Nome\n".
            "9998;CLIENTES DIVERSOS;101;SUPERMERCADISTA;ACME\n"
        ));

        $this->assertSame(
            ['Grp.Vendas', 'Descricao', 'Segmento 1', 'Descricao_2', 'Nome'],
            $leitor->cabecalho()
        );

        $linha = iterator_to_array($leitor->linhas())[3];
        $this->assertSame('CLIENTES DIVERSOS', $linha['Descricao'], 'a primeira Descricao é a do grupo');
        $this->assertSame('SUPERMERCADISTA', $linha['Descricao_2'], 'a segunda é a do segmento');
    }

    public function test_remove_o_bom_e_apara_o_padding_de_nome_e_valor(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "\xEF\xBB\xBF200 - PEDIDOS EM ABERTO COM STATUS.RLT;;\n".
            "Codigo      ;Nome        \n".
            "000042      ;ACME        \n"
        ));

        $this->assertSame('200 - PEDIDOS EM ABERTO COM STATUS.RLT', $leitor->titulo());
        $this->assertSame(['Codigo', 'Nome'], $leitor->cabecalho());
        $this->assertSame(['Codigo' => '000042', 'Nome' => 'ACME'], iterator_to_array($leitor->linhas())[3]);
    }

    public function test_preserva_acento_em_utf8(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "210 - CADASTRO DE CLIENTES.RLT;;\n".
            "Nome;Municipio\n".
            "SÃO JOÃO COMÉRCIO;SÃO PAULO\n"
        ));

        $this->assertSame('SÃO JOÃO COMÉRCIO', iterator_to_array($leitor->linhas())[3]['Nome']);
    }

    /** Rodapé e linha em branco do fim do arquivo não podem virar registro vazio. */
    public function test_descarta_linha_com_menos_colunas_que_o_cabecalho(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "198 - FATURAMENTO EQUIPE.RLT;;;\n".
            "FILIAL;EMISSAO;VLR_TOTAL\n".
            "01;01/09/2026;100,00\n".
            "TOTAL GERAL\n".
            "\n"
        ));

        $this->assertCount(1, iterator_to_array($leitor->linhas()));
    }

    public function test_exigir_colunas_falha_dizendo_o_que_faltou(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo(
            "232 - CONSULTA DE PEDIDOS EMITIDOS META DE VENDAS.RLT;;\n".
            "PEDIDO;COD_PROD\n".
            "930118;V21396\n"
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DT_FATURAMENTO/');

        $leitor->exigirColunas(['PEDIDO', 'DT_FATURAMENTO']);
    }

    public function test_arquivo_inexistente_falha_cedo(): void
    {
        $this->expectException(RuntimeException::class);

        LeitorRelatorio::abrir('/relatorios/nao-existe.csv');
    }

    /** Coluna sem nome existe de verdade no base_marco (duas, no fim). */
    public function test_coluna_sem_nome_recebe_nome_pela_posicao(): void
    {
        $leitor = LeitorRelatorio::abrir($this->arquivo("FONTE;;sai\nABRAS;x;n\n"));

        $this->assertSame(['FONTE', 'coluna_2', 'sai'], $leitor->cabecalho());
    }
}
