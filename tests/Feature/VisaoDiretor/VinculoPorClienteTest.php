<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\Segmento;
use App\Services\VisaoDiretor\VinculoPorCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sugestão de vínculo pelo nome das FILIAIS. Cada caso nasceu de um acerto ou de um falso
 * positivo visto no dry-run contra o palma_v2 em 06/10/2026.
 */
class VinculoPorClienteTest extends TestCase
{
    use RefreshDatabase;

    private int $filiais = 0;

    private function filial(string $codigo, string $grupo, string $segmento, string $fantasia, string $razao = 'EMPRESA QUALQUER LTDA'): void
    {
        $this->filiais++;
        Cliente::create([
            'cod_cliente' => $codigo,
            'loja' => str_pad((string) $this->filiais, 4, '0', STR_PAD_LEFT),
            'razao_social' => $razao,
            'nome_fantasia' => $fantasia,
            'cod_grupo' => $grupo,
            'cod_segmento' => $segmento,
            'cod_vendedor' => '000001',
            'estado' => 'SP',
        ]);
    }

    private function sugerir(string $conta, string $segmento): array
    {
        $vinculo = app(VinculoPorCliente::class);

        return $vinculo->sugerir($conta, $segmento, $vinculo->catalogo());
    }

    public function test_cliente_sem_grupo_entra_pelo_nome_fantasia(): void
    {
        $this->filial('000010', '9998', '120', 'NORMATEL', 'MB COMERCIO DE MATERIAIS LTDA');

        $r = $this->sugerir('NORMATEL', '120');

        $this->assertSame([], $r['grupos'], '9998 nunca vira grupo');
        $this->assertSame(['000010'], $r['clientes']);
    }

    /** Mutação: tirar a fronteira de palavra (só `str_starts_with`). */
    public function test_casa_pelo_comeco_com_fronteira_de_palavra(): void
    {
        $this->filial('000020', '9998', '113', 'BRASIL PARK JABAQUARA');
        $this->filial('000021', '9998', '113', 'SANTA LOLLA - BRASIL PARK SHOP');
        $this->filial('000022', '9998', '113', 'BRASIL PARKING CENTER');

        $this->assertSame(['000020'], $this->sugerir('BRASIL PARK', '113')['clientes']);
    }

    /** Mutação: tirar a trava de segmento das filiais avulsas. */
    public function test_filial_avulsa_so_no_segmento_da_conta(): void
    {
        $this->filial('000030', '9998', '114', 'REDE FURNAS');
        $this->filial('000031', '9998', '123', 'FURNAS - CENTRAIS ELETRICAS');

        $this->assertSame(['000030'], $this->sugerir('Rede Furnas', '114')['clientes']);
    }

    /**
     * Grupo entra quando a maioria das filiais dele casa — e traz junto as filiais com
     * nome diferente da mesma rede ("AUTO BRASIL ESTAC"). Mutação: trocar `>=` por `>` na
     * maioria, ou exigir 100%.
     */
    public function test_grupo_entra_pela_maioria_e_traz_as_filiais_de_outro_nome(): void
    {
        $this->filial('000040', '1339', '113', 'BRASIL PARK CAJAMAR');
        $this->filial('000040', '1339', '113', 'AUTO BRASIL ESTAC - BRASIL PAR');
        $this->filial('000041', '1339', '113', 'BRASIL PARK GUARULHOS');
        $this->filial('000042', '1339', '113', 'PCP PARKING');

        // Grupo de outra empresa com UMA loja de nome parecido não entra.
        $this->filial('000050', '465', '108', 'SANTA LOLLA');
        $this->filial('000051', '465', '113', 'BRASIL PARK SHOPPING');
        $this->filial('000052', '465', '108', 'SANTA LOLLA 2');

        $r = $this->sugerir('BRASIL PARK', '113');

        // O grupo 465 fica de fora; a loja que casou entra sozinha, pelo código dela.
        $this->assertSame(['1339'], $r['grupos']);
        $this->assertSame(['000051'], $r['clientes']);
    }

    /** Mutação: tirar a maioria do código avulso. */
    public function test_codigo_com_muitas_filiais_nao_entra_por_uma(): void
    {
        $this->filial('000800', '9998', '109', 'DROGARIA VIDA');
        $this->filial('000800', '9998', '109', 'ESCOLA 1');
        $this->filial('000800', '9998', '109', 'ESCOLA 2');

        $this->assertSame([], $this->sugerir('VIDA FARMÁCIAS', '109')['clientes']);
    }

    public function test_nome_igual_com_palavra_curta_casa(): void
    {
        $this->filial('000060', '9998', '109', 'DROGARIA VIDA');

        $this->assertSame(['000060'], $this->sugerir('VIDA FARMÁCIAS', '109')['clientes']);
    }

    /** Mutação: tirar `MINIMO_PALAVRA_UNICA`. "MINHA" pegava "MINHA DROGARIA BOA". */
    public function test_palavra_unica_curta_nao_casa_comeco_de_nome_maior(): void
    {
        $this->filial('000070', '9998', '109', 'MINHA DROGARIA BOA');

        $this->assertSame([], $this->sugerir('MINHA FARMACIA', '109')['clientes']);
    }

    /** Mutação: tirar a trava do cliente curto. "POSTO DO PARQUE" casava "Posto Parque Dez". */
    public function test_nome_curto_do_cliente_nao_casa_conta_maior(): void
    {
        $this->filial('000080', '9998', '114', 'POSTO DO PARQUE');

        $this->assertSame([], $this->sugerir('Posto Parque Dez', '114')['clientes']);
    }

    public function test_palavra_generica_sozinha_nao_casa(): void
    {
        $this->filial('000090', '9998', '120', 'ELETRICA NICOLUCCI');

        $this->assertSame([], $this->sugerir('LOJA ELETRICA', '120')['clientes']);
    }

    /** Produção, 09/10/2026: a conta "BURGUER KING" não achava o grupo BURGER KING (66 lojas). */
    public function test_variacao_de_grafia_casa(): void
    {
        $this->filial('000086', '75', '112', 'BURGER KING - SPM');
        $this->filial('000087', '75', '112', 'BURGER KING');
        $this->filial('000120', '1305', '112', 'KOPENHAGEM MOOCA');
        $this->filial('000121', '9998', '109', 'REDE COOPERFARMA');
        $this->filial('000122', '9998', '112', 'SPOLETO2');
        $this->filial('000123', '9998', '112', 'CASA DO BOLO');

        $this->assertSame(['75'], $this->sugerir('BURGUER KING', '112')['grupos']);
        $this->assertSame(['1305'], $this->sugerir('KOPENHAGEN', '112')['grupos']);
        $this->assertSame(['000121'], $this->sugerir('COPERFARMA', '109')['clientes']);
        $this->assertSame(['000122'], $this->sugerir('SPOLETO', '112')['clientes']);
        $this->assertSame(['000123'], $this->sugerir('CASA DE BOLOS', '112')['clientes']);
    }

    /** Distância de edição foi descartada por isto: letra TROCADA é outra marca. */
    public function test_letra_trocada_nao_casa(): void
    {
        $this->filial('000130', '864', '113', 'LE PARK ESTACIONAMENTOS');
        $this->filial('000131', '9998', '120', 'SUPERMERCADO BERTAO');
        $this->filial('000132', '9998', '101', 'POSTO MOUTINHO');

        $this->assertSame([], $this->sugerir('GEPARK', '113')['grupos']);
        $this->assertSame([], $this->sugerir('SERTAO', '120')['clientes']);
        $this->assertSame([], $this->sugerir('GRUPO COUTINHO', '101')['clientes']);
    }

    /** "PIZZAS" vira "PIZA" na grafia, mas continua sendo palavra genérica. */
    public function test_generica_decidida_pelo_nome_escrito(): void
    {
        $this->filial('000140', '9998', '109', 'FARMACIAS PAGUE MENOS');

        $this->assertSame([], $this->sugerir('FARMACIAS', '109')['clientes']);
    }

    public function test_conta_com_duas_marcas_procura_as_duas(): void
    {
        $this->filial('000100', '9998', '109', 'FARMACIA DESCONTO FACIL');
        $this->filial('000101', '9998', '109', 'ASFAR');

        $this->assertSame(['000100', '000101'], $this->sugerir('ASFAR / DESCONTO FACIL', '109')['clientes']);
    }

    /**
     * O comando: acrescenta como sugestão, não toca conta com vínculo manual, e o que
     * casa com duas contas fica fora das duas.
     */
    public function test_comando_grava_sugestao_e_respeita_manual_e_conflito(): void
    {
        $seg = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        $this->filial('000110', '9998', '109', 'BIFARMA CAIEIRAS');
        $this->filial('000111', '9998', '109', 'NISSEI CENTRO');
        $this->filial('000112', '9998', '109', 'SANTA LUCIA');

        $bifarma = ContaEstrategica::create(['segmento_id' => $seg->id, 'nome' => 'BIFARMA']);
        $nissei = ContaEstrategica::create(['segmento_id' => $seg->id, 'nome' => 'NISSEI']);
        $nissei->vinculos()->create(['tipo' => 'cliente', 'codigo' => '999999', 'origem' => ContaEstrategicaVinculo::ORIGEM_MANUAL]);
        $lucia1 = ContaEstrategica::create(['segmento_id' => $seg->id, 'nome' => 'DROGARIA SANTA LUCIA']);
        $lucia2 = ContaEstrategica::create(['segmento_id' => $seg->id, 'nome' => 'FARMACIA SANTA LUCIA']);

        $this->artisan('visao-diretor:sugerir-vinculos', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $bifarma->vinculos()->count(), 'dry-run não grava');

        $this->artisan('visao-diretor:sugerir-vinculos')->assertSuccessful();

        $this->assertSame(
            [['cliente', '000110', 'sugestao']],
            $bifarma->vinculos()->get()->map(fn ($v) => [$v->tipo, $v->codigo, $v->origem])->all(),
        );
        $this->assertSame(['999999'], $nissei->vinculos()->pluck('codigo')->all());
        $this->assertSame(0, $lucia1->vinculos()->count());
        $this->assertSame(0, $lucia2->vinculos()->count());
        $this->assertSame(1, (int) $bifarma->fresh()->vinculos_versao, 'gravou pelo ClientesDaConta (sobe a versão do cache)');

        // Rodar de novo não muda nada.
        $this->artisan('visao-diretor:sugerir-vinculos')->assertSuccessful();
        $this->assertSame(1, (int) $bifarma->fresh()->vinculos_versao);
    }
}
