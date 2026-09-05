<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Faturamento;
use App\Models\PotencialPeso;
use App\Models\Produto;
use App\Models\Segmento;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Potencial\FamiliaProduto;
use App\Services\Potencial\PotencialCarteiraResolver;
use Database\Seeders\PotencialPesoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Potencial da Carteira: de quem já compra deste vendedor, quantos ainda não compram cada
 * família de produto.
 *
 * As decisões que estes testes travam estão explicadas no docblock do
 * {@see PotencialCarteiraResolver} — denominador é cliente ativo, grão é `cod_cliente`, e
 * "já compra" significa "eu já vendi".
 */
class PotencialCarteiraTest extends TestCase
{
    use RefreshDatabase;

    private const COD = '010617';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        Segmento::create(['codigo' => '103', 'nome' => 'ORGAO PUBLICO']);
        $this->seed(PotencialPesoSeeder::class);

        Produto::create(['cod_produto' => 'B1', 'descricao' => 'Bobina 80x80', 'categoria' => 'BOBINA']);
        Produto::create(['cod_produto' => 'E1', 'descricao' => 'Etiqueta 40x40', 'categoria' => 'ETIQUETA']);
        Produto::create(['cod_produto' => 'T1', 'descricao' => 'Tag gôndola', 'categoria' => 'TAG']);
        Produto::create(['cod_produto' => 'S1', 'descricao' => 'Papel A4', 'categoria' => 'SUPLY']);
    }

    private function cliente(string $cod, string $loja = '01', string $segmento = '101', string $vendedor = self::COD): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => "Cliente {$cod}/{$loja}",
            'cod_vendedor' => $vendedor,
            'cod_segmento' => $segmento,
        ]);
    }

    private function nota(string $codCliente, string $codProduto, ?string $data = null, string $vendedor = self::COD): void
    {
        Faturamento::create([
            'nota_fiscal' => (string) fake()->unique()->numberBetween(1, 999999),
            'data_emissao' => $data ?? now()->subMonth()->toDateString(),
            'cod_cliente' => $codCliente,
            'cod_vendedor' => $vendedor,
            'cod_produto' => $codProduto,
            'quantidade' => 1,
            'valor_unitario' => 100,
            'valor_total' => 100,
        ]);
    }

    /** @return array<string, array<string, mixed>> família => linha */
    private function porFamilia(array $resultado): array
    {
        return collect($resultado['familias'])->keyBy('familia')->all();
    }

    private function resolver(): array
    {
        return app(PotencialCarteiraResolver::class)->resolver([self::COD]);
    }

    #[Test]
    public function test_conta_quem_nao_compra_cada_familia(): void
    {
        $this->cliente('C1');
        $this->cliente('C2');
        $this->cliente('C3');

        $this->nota('C1', 'B1');
        $this->nota('C2', 'B1');
        $this->nota('C2', 'E1');
        $this->nota('C3', 'S1'); // ativo, mas nenhuma das três famílias

        $familias = $this->porFamilia($this->resolver());

        $this->assertSame(2, $familias['bobina']['compram']);
        $this->assertSame(1, $familias['bobina']['potencial'], 'C3 é ativo e não compra bobina');
        $this->assertSame(1, $familias['etiqueta']['compram']);
        $this->assertSame(2, $familias['etiqueta']['potencial']);
        $this->assertSame(0, $familias['tag']['compram']);
        $this->assertSame(3, $familias['tag']['potencial']);
    }

    /**
     * ⚠️ SUPLY é 74,8% das linhas de faturamento da base — é a linha de suprimentos
     * corporativos (sacola, papel A4, café), outra linha de negócio. Se ela vazasse para
     * dentro de alguma das três famílias, o card viraria ruído.
     */
    #[Test]
    public function test_categoria_fora_das_tres_familias_nao_conta_como_compra(): void
    {
        $this->cliente('C1');
        $this->nota('C1', 'S1');

        $familias = $this->porFamilia($this->resolver());

        foreach (FamiliaProduto::chaves() as $familia) {
            $this->assertSame(0, $familias[$familia]['compram'], "{$familia} não pode contar SUPLY");
        }
    }

    /**
     * ⚠️ O potencial é a CARTEIRA INTEIRA, quebrada em ativos × inativos.
     *
     * O total sozinho não escolhe família — quem está parado não compra nenhuma das três e
     * entra igual nas três. Quem diferencia é `potencialAtivos`, e é por isso que a quebra
     * existe em vez de um número só.
     */
    #[Test]
    public function test_potencial_cobre_a_carteira_inteira_quebrada_em_ativos_e_inativos(): void
    {
        $this->cliente('C1');
        $this->cliente('C2');
        $this->cliente('C3'); // nunca comprou
        $this->cliente('C4'); // nunca comprou

        $this->nota('C1', 'B1');
        $this->nota('C2', 'S1');

        $resultado = $this->resolver();
        $familias = $this->porFamilia($resultado);

        $this->assertSame(4, $resultado['carteira']);
        $this->assertSame(2, $resultado['ativos']);
        $this->assertSame(2, $resultado['inativos']);

        $bobina = $familias['bobina'];
        $this->assertSame(4, $bobina['candidatos'], 'candidato é a carteira toda');
        $this->assertSame(3, $bobina['potencial'], 'C2, C3 e C4 não compram bobina');
        $this->assertSame(1, $bobina['potencialAtivos'], 'só C2 é ativo e não compra bobina');
        $this->assertSame(2, $bobina['potencialInativos'], 'C3 e C4 nunca compraram');
        $this->assertSame(
            $bobina['potencial'],
            $bobina['potencialAtivos'] + $bobina['potencialInativos'],
            'a quebra tem que fechar com o total',
        );

        // ⚠️ Inativos entram igual nas três famílias — é o que achata a comparação e a
        // razão de o card mostrar a quebra em vez de só o total.
        foreach (FamiliaProduto::chaves() as $familia) {
            $this->assertSame(2, $familias[$familia]['potencialInativos']);
        }
    }

    /**
     * ⚠️ Regressão: `carteira` somava as contagens por segmento, então um código com
     * filiais em segmentos diferentes era contado uma vez em CADA segmento e o total vinha
     * inflado (178 contra 172 reais num vendedor de dev). Passou a importar quando esse
     * número virou o denominador do card.
     */
    #[Test]
    public function test_carteira_nao_conta_duas_vezes_codigo_multi_segmento(): void
    {
        $this->cliente('C1', '01', '101');
        $this->cliente('C1', '02', '103');
        $this->cliente('C2', '01', '101');

        $resultado = $this->resolver();

        $this->assertSame(2, $resultado['carteira']);
        $this->assertSame(2, $this->porFamilia($resultado)['bobina']['candidatos']);
    }

    /**
     * ⚠️ `faturamentos` não guarda `loja`, então o grão é o CÓDIGO do cliente: três
     * filiais são um cliente só, na carteira e na contagem de ativos.
     *
     * (Aqui quem faz a dedução é o `COUNT(DISTINCT)`, não a tabela derivada — conferido
     * por mutação. O que a derivada resolve está no teste seguinte.)
     */
    #[Test]
    public function test_cliente_com_varias_filiais_conta_uma_vez_so(): void
    {
        $this->cliente('C1', '01');
        $this->cliente('C1', '02');
        $this->cliente('C1', '03');

        $this->nota('C1', 'B1');

        $resultado = $this->resolver();

        $this->assertSame(1, $resultado['carteira'], 'três filiais são um código só');
        $this->assertSame(1, $resultado['ativos']);
        $this->assertSame(1, $this->porFamilia($resultado)['bobina']['compram']);
    }

    /**
     * ⚠️ ESTE é o teste que justifica a tabela derivada do resolver. 1.174 códigos da base
     * (3%) têm filiais em segmentos diferentes. Sem colapsar `clientes` em uma linha por
     * `cod_cliente` antes do join, o mesmo cliente entra no grupo dos DOIS segmentos e é
     * contado duas vezes como ativo — o denominador infla e o potencial vira ficção.
     *
     * Verificado por mutação: trocando a derivada por um join direto em `clientes`, os
     * ativos sobem para 2.
     */
    #[Test]
    public function test_cliente_com_filiais_em_segmentos_diferentes_conta_uma_vez_so(): void
    {
        $this->cliente('C1', '01', '101');
        $this->cliente('C1', '02', '103');

        $this->nota('C1', 'B1');

        $resultado = $this->resolver();

        $this->assertSame(1, $resultado['ativos'], 'um código não pode contar em dois segmentos');
        $this->assertSame(1, $this->porFamilia($resultado)['bobina']['compram']);
        $this->assertSame(0, $this->porFamilia($resultado)['bobina']['potencial']);
    }

    #[Test]
    public function test_compra_fora_da_janela_de_doze_meses_nao_conta(): void
    {
        $this->cliente('C1');
        $this->nota('C1', 'B1', now()->subMonths(13)->toDateString());

        $resultado = $this->resolver();

        $this->assertSame(0, $resultado['ativos'], 'nota de 13 meses atrás não torna o cliente ativo');
        $this->assertSame(0, $this->porFamilia($resultado)['bobina']['compram']);
    }

    #[Test]
    public function test_escopo_do_vendedor_e_respeitado(): void
    {
        $this->cliente('C1');
        $this->cliente('C9', '01', '101', '999999');

        $this->nota('C1', 'B1');
        $this->nota('C9', 'B1', null, '999999');

        $resultado = $this->resolver();

        $this->assertSame(1, $resultado['carteira']);
        $this->assertSame(1, $resultado['ativos']);
    }

    /**
     * ⚠️ Peso 0 tira o segmento dos candidatos daquela família — é como a direção vai
     * dizer "posto de gasolina não compra tag".
     */
    #[Test]
    public function test_peso_zero_tira_o_segmento_dos_candidatos(): void
    {
        $this->cliente('C1', '01', '101'); // SUPERMERCADISTA
        $this->cliente('C2', '01', '103'); // ORGAO PUBLICO
        $this->nota('C1', 'S1');
        $this->nota('C2', 'S1');

        $orgaoPublico = Segmento::where('codigo', '103')->firstOrFail();
        PotencialPeso::where('segmento_id', $orgaoPublico->id)->where('familia', 'tag')->update(['peso' => 0]);

        $familias = $this->porFamilia($this->resolver());

        $this->assertSame(1, $familias['tag']['candidatos'], 'órgão público sai dos candidatos a tag');
        $this->assertSame(2, $familias['bobina']['candidatos'], 'bobina continua valendo para os dois');
    }

    /**
     * ⚠️ A invariante que mantém o potencial não-negativo SEM precisar de `max(0, …)`:
     * quem compra a família e quem está ativo têm de sair dos MESMOS segmentos aprovados
     * pelo peso. Cliente de segmento com peso 0 fica fora dos dois lados.
     *
     * Este teste substituiu um "potencial nunca fica negativo" que a verificação por
     * mutação reprovou: remover o `max(0, …)` do resolver não o fazia falhar, porque o
     * cenário que ele descrevia era impossível. Aqui a mutação morde de verdade — mover o
     * `compram +=` para fora do filtro de peso faz `compram` (1) exceder `candidatos` (0)
     * e o potencial vai a -1.
     */
    #[Test]
    public function test_segmento_com_peso_zero_sai_dos_dois_lados_da_conta(): void
    {
        $this->cliente('C2', '01', '103');
        $this->nota('C2', 'T1');

        $orgaoPublico = Segmento::where('codigo', '103')->firstOrFail();
        PotencialPeso::where('segmento_id', $orgaoPublico->id)->where('familia', 'tag')->update(['peso' => 0]);

        $tag = $this->porFamilia($this->resolver())['tag'];

        $this->assertSame(0, $tag['candidatos'], 'segmento de peso 0 não é candidato');
        $this->assertSame(0, $tag['compram'], 'e a compra dele também não pode entrar');
        $this->assertSame(0, $tag['potencial']);
        $this->assertGreaterThanOrEqual(0, $tag['potencial']);
    }

    /**
     * O card mostra uma faixa âmbar enquanto os pesos forem todos 1 — sem ela o vendedor
     * leria a ordem das famílias como prioridade definida pela empresa.
     */
    #[Test]
    public function test_pesos_padrao_indica_quando_a_matriz_ainda_nao_foi_definida(): void
    {
        $this->assertTrue($this->resolver()['pesosPadrao']);

        PotencialPeso::query()->limit(1)->update(['peso' => 2.5]);

        $this->assertFalse($this->resolver()['pesosPadrao']);
    }

    #[Test]
    public function test_familia_desconhecida_estoura(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FamiliaProduto::categoriaDe('cartucho');
    }

    /** O seeder roda em toda restauração de banco; peso editado pela direção não pode sumir. */
    #[Test]
    public function test_seeder_nao_sobrescreve_peso_ja_editado(): void
    {
        $peso = PotencialPeso::query()->firstOrFail();
        $peso->update(['peso' => 4.25]);

        $this->seed(PotencialPesoSeeder::class);

        $this->assertSame('4.25', (string) $peso->fresh()->peso);
        $this->assertSame(6, PotencialPeso::query()->count(), '2 segmentos × 3 famílias, sem duplicar');
    }

    #[Test]
    public function test_vendedor_recebe_o_bloco_e_gestor_nao(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => self::COD]);

        $props = $this->actingAs($vendedor)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($props['potencialCarteira']);
        $this->assertCount(3, $props['potencialCarteira']['familias']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $props = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertNull($props['potencialCarteira'], 'gestor não recebe a agregação sobre faturamentos');
    }

    /**
     * ⚠️ O link "N ativos" do card abre a Carteira por este conjunto, então a lista tem que
     * bater com ESSE número — `potencialAtivos`, não o total do painel. O total inclui os
     * inativos, que não aparecem aqui porque não têm nota nenhuma na janela.
     *
     * É a invariante que mantém card e lista honestos entre si: se divergirem, o vendedor
     * clica em "17 ativos" e cai numa lista de outro tamanho.
     */
    #[Test]
    public function test_codigos_sem_familia_batem_com_o_link_de_ativos(): void
    {
        $this->cliente('C1');
        $this->cliente('C2');
        $this->cliente('C3');
        $this->cliente('C4'); // nunca comprou: não é ativo, não entra

        $this->nota('C1', 'B1');
        $this->nota('C2', 'E1');
        $this->nota('C3', 'S1');

        $codigos = app(PotencialCarteiraResolver::class)->codigosSemFamilia([self::COD], 'etiqueta');
        sort($codigos);

        $this->assertSame(['C1', 'C3'], $codigos);
        $this->assertCount(
            $this->porFamilia($this->resolver())['etiqueta']['potencialAtivos'],
            $codigos,
            'a lista tem que ter exatamente o tamanho do link "N ativos"',
        );
    }

    /**
     * ⚠️ REGRESSÃO da auditoria de 2026-09-05, que encontrou 130 divergências em 121
     * vendedores: `codigosSemFamilia()` não juntava a carteira, então a lista incluía quem
     * comprou com o código deste vendedor mas JÁ SAIU da carteira dele — cliente
     * transferido carrega o histórico no código antigo. O contador do card sempre juntou a
     * carteira, então o link abria uma lista MAIOR que o número anunciado.
     *
     * As duas pontas têm que partir do mesmo universo. Sem este teste, o defeito volta
     * calado: o número continua plausível e a lista continua abrindo.
     */
    #[Test]
    public function test_cliente_que_saiu_da_carteira_nao_entra_na_lista(): void
    {
        $this->cliente('C1');
        // Comprou com o código deste vendedor, mas hoje está na carteira de outro.
        $this->cliente('C9', '01', '101', '999999');

        $this->nota('C1', 'B1');
        $this->nota('C9', 'B1');

        $codigos = app(PotencialCarteiraResolver::class)->codigosSemFamilia([self::COD], 'etiqueta');

        $this->assertSame(['C1'], $codigos, 'C9 não é mais deste vendedor');
        $this->assertCount(
            $this->porFamilia($this->resolver())['etiqueta']['potencialAtivos'],
            $codigos,
            'lista e contador têm que partir do mesmo universo',
        );
    }

    /**
     * ⚠️ Cliente que só compra produto sem cadastro em `produtos` continua ativo e continua
     * sem a família — precisa aparecer na lista. Com INNER JOIN ele sumiria daqui e o card
     * passaria a anunciar um número maior que a lista entrega.
     */
    #[Test]
    public function test_cliente_de_produto_orfao_entra_na_lista(): void
    {
        $this->cliente('C1');
        $this->nota('C1', 'PRODUTO-SEM-CADASTRO');

        $this->assertSame(
            ['C1'],
            app(PotencialCarteiraResolver::class)->codigosSemFamilia([self::COD], 'bobina'),
        );
    }

    #[Test]
    public function test_carteira_filtra_por_familia_e_anuncia_o_recorte(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => self::COD]);

        $this->cliente('C1');
        $this->cliente('C2');
        $this->nota('C1', 'B1');
        $this->nota('C2', 'E1');

        $props = $this->actingAs($vendedor)
            ->get(route('carteira.index', ['sem_familia' => 'etiqueta']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('etiqueta', $props['filtros']['semFamilia']);
        $this->assertSame('Etiqueta', $props['filtros']['semFamiliaRotulo']);
        $this->assertSame(1, $props['kpis']['total'], 'só C1, que compra bobina e não etiqueta');
    }

    /**
     * ⚠️ A tela lista FILIAIS e o card do Painel conta EMPRESAS. Sem os dois números na
     * faixa, quem clica em "40 ativos" e encontra 86 linhas conclui que o filtro furou.
     * Aqui um código com 3 filiais tem que virar "1 empresa, 3 linhas".
     */
    #[Test]
    public function test_faixa_reconcilia_empresas_com_filiais_listadas(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => self::COD]);

        $this->cliente('C1', '01');
        $this->cliente('C1', '02');
        $this->cliente('C1', '03');
        $this->nota('C1', 'B1');

        $props = $this->actingAs($vendedor)
            ->get(route('carteira.index', ['sem_familia' => 'etiqueta']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['filtros']['semFamiliaEmpresas'], 'um código de cliente');
        $this->assertSame(3, $props['kpis']['total'], 'três filiais na listagem');
    }

    /**
     * ⚠️ A família vem da query string e vira o valor comparado contra
     * `produtos.categoria`. Valor desconhecido não pode filtrar por lixo nem estourar a
     * tela — tem que ser ignorado, e um link velho continua abrindo a carteira inteira.
     */
    #[Test]
    public function test_familia_invalida_na_url_e_ignorada_sem_quebrar_a_carteira(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => self::COD]);

        $this->cliente('C1');
        $this->cliente('C2');

        $props = $this->actingAs($vendedor)
            ->get(route('carteira.index', ['sem_familia' => "bobina') OR 1=1 --"]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('', $props['filtros']['semFamilia']);
        $this->assertNull($props['filtros']['semFamiliaRotulo']);
        $this->assertSame(2, $props['kpis']['total'], 'sem filtro: a carteira inteira');
    }

    /** O rótulo vem do servidor — o front não pode ter cópia da lista de famílias. */
    #[Test]
    public function test_payload_carrega_o_rotulo_de_cada_familia(): void
    {
        $familias = $this->porFamilia($this->resolver());

        $this->assertSame('Bobina', $familias['bobina']['rotulo']);
        $this->assertSame('Etiqueta', $familias['etiqueta']['rotulo']);
        $this->assertSame('Tag de gôndola', $familias['tag']['rotulo']);
    }
}
