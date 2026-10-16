<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Services\VisaoDiretor\Redes\CategoriasDeRede;
use App\Services\VisaoDiretor\Redes\RelatorioDeRedes;
use App\Services\VisaoDiretor\Redes\VinculoDeRedes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Motor de vínculo da página Maiores redes (redes do mercado → clientes e leads). Cada
 * caso nasceu de uma decisão do Tony em 2026-10-16 ou de um conflito visto no dry-run
 * contra o palma_v2.
 */
class VinculoDeRedesTest extends TestCase
{
    use RefreshDatabase;

    private int $loja = 0;

    private function filial(string $codigo, string $segmento, string $fantasia, array $extra = []): void
    {
        $this->loja++;
        Cliente::create($extra + [
            'cod_cliente' => $codigo,
            'loja' => str_pad((string) $this->loja, 4, '0', STR_PAD_LEFT),
            'razao_social' => 'EMPRESA '.$codigo.' LTDA',
            'nome_fantasia' => $fantasia,
            'cod_grupo' => '9998',
            'cod_segmento' => $segmento,
            'cod_vendedor' => '000001',
            'estado' => 'SP',
        ]);
    }

    private function rede(string $nome, string $categoria, array $extra = []): array
    {
        return $extra + ['nome' => $nome, 'apelidos' => [], 'categoria' => $categoria, 'uf' => null, 'lojas' => 10];
    }

    /** @return array<string, string> código → nome da rede */
    private function donos(array $redes): array
    {
        $r = app(VinculoDeRedes::class)->calcular($redes);

        return array_map(fn ($v) => $redes[$v['rede']]['nome'], $r['clientes']);
    }

    public function test_o_json_das_redes_esta_limpo(): void
    {
        $json = json_decode(file_get_contents(database_path('data/visao-diretor/redes-mercado-2026.json')), true);
        $vistas = [];

        foreach ($json['redes'] as $r) {
            $this->assertArrayHasKey($r['categoria'], CategoriasDeRede::CATEGORIAS, $r['nome']);
            $this->assertStringNotContainsString('(', $r['nome'], 'Dono e descrição saem do nome (decisão do Tony)');
            $chave = $r['categoria'].'|'.mb_strtoupper($r['nome']);
            $this->assertArrayNotHasKey($chave, $vistas, "Rede repetida: {$chave}");
            $vistas[$chave] = true;
        }

        // Bandeiras somadas viram uma linha cada.
        $carrefour = array_column(array_filter($json['redes'], fn ($r) => $r['posicao'] === 49), 'nome');
        $this->assertSame(['CARREFOUR', 'ATACADÃO', "SAM'S CLUB"], $carrefour);
    }

    /** Franqueado conta como loja da rede: casa pelo nome fantasia, com CNPJ próprio. */
    public function test_franqueado_entra_pelo_nome_fantasia(): void
    {
        $this->filial('000010', '112', 'CACAU SHOW - SHOPPING ARICANDUVA');
        $this->filial('000011', '112', 'CACAU BRASIL DOCES');

        $this->assertSame(['000010' => 'CACAU SHOW'], $this->donos([$this->rede('CACAU SHOW', 'alimentacao')]));
    }

    /** Categoria sem segmento do TOTVS: a filial vale em qualquer segmento, mas as travas de nome seguem. */
    public function test_categoria_sem_segmento_aceita_qualquer_segmento_com_travas_de_nome(): void
    {
        $this->filial('000020', '101', 'OTICAS CAROL - MOOCA');
        $this->filial('000021', '108', 'AVON COSMETICOS CENTRO');
        $this->filial('000022', '108', 'AVON');

        $donos = $this->donos([$this->rede('ÓTICAS CAROL', 'oticas'), $this->rede('AVON', 'beleza_saude')]);

        $this->assertSame('ÓTICAS CAROL', $donos['000020']);
        // Palavra única de 4 letras só casa nome IGUAL.
        $this->assertArrayNotHasKey('000021', $donos);
        $this->assertSame('AVON', $donos['000022']);
    }

    /** Mutação: tirar o peso do resolver (empate vira conflito e o cliente some). */
    public function test_nome_mais_especifico_ganha(): void
    {
        $this->filial('000030', '101', 'CARREFOUR EXPRESS PAULISTA');
        $this->filial('000031', '101', 'CARREFOUR PINHEIROS');

        $donos = $this->donos([$this->rede('CARREFOUR', 'supermercados'), $this->rede('CARREFOUR EXPRESS', 'conveniencia')]);

        $this->assertSame(['000030' => 'CARREFOUR EXPRESS', '000031' => 'CARREFOUR'], $donos);
    }

    /** Cada cliente pertence a UMA rede: empate em tudo fica fora das duas. */
    public function test_empate_fica_fora_e_vai_para_conflitos(): void
    {
        $this->filial('000040', '101', 'MUNDIAL CENTRO');

        $redes = [$this->rede('MUNDIAL', 'conveniencia'), $this->rede('MUNDIAL', 'oticas')];
        $r = app(VinculoDeRedes::class)->calcular($redes);

        $this->assertSame([], $r['clientes']);
        $this->assertCount(1, $r['conflitos']);
        $this->assertNull($r['conflitos'][0]['decidido']);
    }

    /** "FARMÁCIAS SÃO PAULO" (PR) e "DROGARIA SÃO PAULO" (SP) têm a mesma marca. */
    public function test_empate_desempata_pela_uf_da_rede(): void
    {
        $this->filial('000050', '109', 'FARMACIA SAO PAULO CENTRO', ['estado' => 'PR']);
        $this->filial('000051', '109', 'DROGARIA SAO PAULO MOOCA', ['estado' => 'SP']);

        $donos = $this->donos([
            $this->rede('DROGARIA SÃO PAULO', 'drogarias', ['uf' => 'SP', 'lojas' => 1000]),
            $this->rede('FARMACIAS SÃO PAULO', 'drogarias', ['uf' => 'PR', 'lojas' => 33]),
        ]);

        $this->assertSame('FARMACIAS SÃO PAULO', $donos['000050']);
        $this->assertSame('DROGARIA SÃO PAULO', $donos['000051']);
    }

    /** O grupo CARREFOUR do TOTVS tem lojas ATACADAO: elas ficam com quem as casou pelo nome. */
    public function test_nome_ganha_de_quem_so_trouxe_pelo_grupo(): void
    {
        GrupoCliente::create(['codigo' => '500', 'nome' => 'CARREFOUR']);
        $this->filial('000060', '101', 'CARREFOUR PINHEIROS', ['cod_grupo' => '500']);
        $this->filial('000061', '101', 'CARREFOUR BUTANTA', ['cod_grupo' => '500']);
        $this->filial('000062', '101', 'ATACADAO GUARULHOS', ['cod_grupo' => '500']);

        $donos = $this->donos([$this->rede('CARREFOUR', 'supermercados'), $this->rede('ATACADÃO', 'supermercados')]);

        $this->assertSame('CARREFOUR', $donos['000060']);
        $this->assertSame('ATACADÃO', $donos['000062']);
    }

    /** Mutação: deixar a expansão por raiz pegar código já ligado a outra rede. */
    public function test_mesma_empresa_nao_rouba_cliente_de_outra_rede(): void
    {
        $this->filial('000075', '101', 'ARMAZEM DA MOOCA', ['cnpj' => '22.333.444/0001-81']);
        $this->filial('000076', '101', 'SUPERMERCADO NIPPON', ['cnpj' => '22.333.444/0002-62']);
        $this->filial('000077', '101', 'DEPOSITO NIPPON', ['cnpj' => '22.333.444/0003-43']);
        $so = app(VinculoDeRedes::class)->calcular([$this->rede('ARMAZEM DA MOOCA', 'supermercados')]);
        // Mesma raiz, nenhuma outra rede: os outros códigos da empresa vêm junto.
        $this->assertSame(['rede' => 0, 'nivel' => VinculoDeRedes::MESMA_EMPRESA], $so['clientes']['000077']);
        Cliente::query()->delete();

        $this->filial('000070', '101', 'ATACADAO OSASCO', ['cnpj' => '75.315.333/0001-09']);
        $this->filial('000071', '101', 'CARREFOUR BAIRRO PERDIZES', ['cnpj' => '75.315.333/0002-81']);
        $this->filial('000072', '101', 'DEPOSITO CENTRAL', ['cnpj' => '75.315.333/0003-62']);

        $r = app(VinculoDeRedes::class)->calcular([$this->rede('ATACADÃO', 'supermercados'), $this->rede('CARREFOUR BAIRRO', 'conveniencia')]);

        $this->assertSame(0, $r['clientes']['000070']['rede']);
        $this->assertSame(1, $r['clientes']['000071']['rede'], 'Casou pelo nome com outra rede: a raiz não tira');
        // A raiz está nas duas redes: o depósito sem nome de nenhuma não tem como ser decidido.
        $this->assertArrayNotHasKey('000072', $r['clientes']);
        $this->assertContains('000072', array_column($r['conflitos'], 'codigo'));
    }

    /** Lead cuja raiz já é cliente fica de fora (a empresa já está na linha do cliente). */
    public function test_lead_de_empresa_que_ja_e_cliente_fica_de_fora(): void
    {
        $this->filial('000080', '108', 'LOJA QUALQUER', ['cnpj' => '11.222.333/0001-81']);
        $ja = Lead::create(['origem' => 'manual', 'status' => 'ativo', 'nome' => 'RI HAPPY MORUMBI', 'razao_social' => 'RI HAPPY MORUMBI', 'cnpj' => '11222333000262']);
        $novo = Lead::create(['origem' => 'manual', 'status' => 'ativo', 'nome' => 'RI HAPPY CAMPINAS', 'razao_social' => 'RI HAPPY CAMPINAS', 'cnpj' => '44555666000110']);
        $excluido = Lead::create(['origem' => 'manual', 'status' => 'excluido', 'nome' => 'RI HAPPY SANTOS', 'razao_social' => 'RI HAPPY SANTOS', 'cnpj' => '77888999000110']);

        $r = app(VinculoDeRedes::class)->calcular([$this->rede('RI HAPPY', 'rede_lojas')]);

        $this->assertSame([$novo->id], array_keys($r['leads']));
        $this->assertArrayNotHasKey($ja->id, $r['leads']);
        $this->assertArrayNotHasKey($excluido->id, $r['leads']);
    }

    /** Status: só Ativo e A trabalhar; a rede é Ativa com pelo menos um cliente ativo. */
    public function test_relatorio_tem_so_ativo_e_a_trabalhar(): void
    {
        $this->filial('000090', '112', 'KOPENHAGEN MOEMA', ['data_ultima_compra' => now()->subDays(10)]);
        $this->filial('000091', '112', 'KOPENHAGEN IBIRAPUERA', ['data_ultima_compra' => now()->subDays(400)]);
        $this->filial('000092', '112', 'SPOLETO CENTRO', ['data_ultima_compra' => now()->subDays(300)]);
        Lead::create(['origem' => 'manual', 'status' => 'ativo', 'nome' => 'SPOLETO OSASCO', 'razao_social' => 'SPOLETO OSASCO', 'cnpj' => '44555666000110']);

        $json = ['redes' => [
            $this->rede('KOPENHAGEN', 'alimentacao') + ['posicao' => 1, 'linha_original' => 'GRUPO CRM (KOPENHAGEN / BRASIL CACAU)', 'qualidade' => 'pesquisado', 'fonte' => null],
            $this->rede('SPOLETO', 'alimentacao') + ['posicao' => 2, 'linha_original' => 'SPOLETO', 'qualidade' => 'atualizado', 'fonte' => null],
        ], 'puladas' => []];

        $r = app(RelatorioDeRedes::class)->montar($json);
        $redes = array_column($r['redes'], null, 'rede');

        $this->assertSame(RelatorioDeRedes::ATIVO, $redes['KOPENHAGEN']['status']);
        $this->assertSame(1, $redes['KOPENHAGEN']['clientes_ativos']);
        $this->assertSame(RelatorioDeRedes::A_TRABALHAR, $redes['SPOLETO']['status'], 'Perdendo e lead são A trabalhar');
        $this->assertSame(1, $redes['SPOLETO']['leads']);
        $this->assertEqualsCanonicalizing(
            [RelatorioDeRedes::ATIVO, RelatorioDeRedes::A_TRABALHAR],
            array_values(array_unique(array_column($r['vinculos'], 'status'))),
        );
    }
}
