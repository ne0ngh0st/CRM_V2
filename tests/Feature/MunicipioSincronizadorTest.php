<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Services\Geografia\MunicipioSincronizador;
use App\Services\PowerBi\SchemaBi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `clientes.cod_municipio`: o texto de município do TOTVS virando código IBGE.
 *
 * O que importa travar é que a resolução usa a MESMA normalização das views do Power BI
 * (`ViewsBi::nomeMunicipio`) e que UF faz parte da chave — nome sozinho funde cidades
 * homônimas, e o mapa plotaria clientes do Piauí no Rio Grande do Sul.
 */
class MunicipioSincronizadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table(SchemaBi::nome().'.de_para_municipio')->insert([
            ['uf' => 'SP', 'nome_norm' => 'SÃO PAULO', 'cod_municipio' => 3550308, 'origem' => 'IBGE'],
            ['uf' => 'SP', 'nome_norm' => 'SANTA BARBARA D OESTE', 'cod_municipio' => 3545803, 'origem' => 'IBGE'],
            ['uf' => 'PI', 'nome_norm' => 'BOM JESUS', 'cod_municipio' => 2201903, 'origem' => 'IBGE'],
            ['uf' => 'RS', 'nome_norm' => 'BOM JESUS', 'cod_municipio' => 4302303, 'origem' => 'IBGE'],
        ]);
    }

    private function cliente(string $cod, ?string $uf, ?string $municipio): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $cod,
            'loja' => '0001',
            'razao_social' => "CLIENTE {$cod}",
            'cod_vendedor' => '001',
            'estado' => $uf,
            'municipio' => $municipio,
        ]);
    }

    private function codigoDe(Cliente $cliente): ?int
    {
        $valor = DB::table('clientes')->where('id', $cliente->id)->value('cod_municipio');

        return $valor === null ? null : (int) $valor;
    }

    public function test_resolve_pelo_nome_normalizado_e_pela_uf(): void
    {
        $semAcento = $this->cliente('1', 'SP', 'sao paulo');
        $comApostrofo = $this->cliente('2', 'SP', "  Santa Barbara d'Oeste ");
        $piaui = $this->cliente('3', 'PI', 'BOM JESUS');
        $gaucho = $this->cliente('4', 'RS', 'BOM JESUS');
        $ufErrada = $this->cliente('5', 'RJ', 'BOM JESUS');
        $desconhecido = $this->cliente('6', 'SP', 'CIDADE QUE NAO EXISTE');
        $semMunicipio = $this->cliente('7', 'SP', null);

        $alteradas = app(MunicipioSincronizador::class)->sincronizar();

        $this->assertSame(4, $alteradas);
        $this->assertSame(3550308, $this->codigoDe($semAcento));
        $this->assertSame(3545803, $this->codigoDe($comApostrofo));
        $this->assertSame(2201903, $this->codigoDe($piaui));
        $this->assertSame(4302303, $this->codigoDe($gaucho));
        $this->assertNull($this->codigoDe($ufErrada), 'homônimo de outra UF não pode emprestar o código');
        $this->assertNull($this->codigoDe($desconhecido));
        $this->assertNull($this->codigoDe($semMunicipio));
    }

    public function test_rodar_de_novo_nao_escreve_nada(): void
    {
        $this->cliente('1', 'SP', 'SAO PAULO');
        $this->cliente('2', 'SP', 'CIDADE QUE NAO EXISTE');

        $sincronizador = app(MunicipioSincronizador::class);

        $this->assertSame(1, $sincronizador->sincronizar());
        $this->assertSame(0, $sincronizador->sincronizar());
    }

    public function test_cadastro_corrigido_no_totvs_troca_ou_apaga_o_codigo(): void
    {
        $mudou = $this->cliente('1', 'PI', 'BOM JESUS');
        $sumiu = $this->cliente('2', 'SP', 'SAO PAULO');

        $sincronizador = app(MunicipioSincronizador::class);
        $sincronizador->sincronizar();

        // O TOTVS corrige a UF de um e o outro passa a vir com um texto que não resolve.
        DB::table('clientes')->where('id', $mudou->id)->update(['estado' => 'RS']);
        DB::table('clientes')->where('id', $sumiu->id)->update(['municipio' => 'XXXX']);

        $this->assertSame(2, $sincronizador->sincronizar());
        $this->assertSame(4302303, $this->codigoDe($mudou));
        $this->assertNull($this->codigoDe($sumiu), 'código velho não pode sobreviver a um município que deixou de resolver');
    }

    public function test_cobertura_e_nao_resolvidos(): void
    {
        $this->cliente('1', 'SP', 'SAO PAULO');
        $this->cliente('2', 'SP', 'XXXX');
        $this->cliente('3', 'SP', 'XXXX');

        $sincronizador = app(MunicipioSincronizador::class);
        $sincronizador->sincronizar();

        $this->assertSame(['total' => 3, 'resolvidos' => 1], $sincronizador->cobertura());

        $pendentes = $sincronizador->naoResolvidos();
        $this->assertCount(1, $pendentes);
        $this->assertSame('XXXX', $pendentes[0]->municipio);
        $this->assertSame(2, (int) $pendentes[0]->filiais);
    }
}
