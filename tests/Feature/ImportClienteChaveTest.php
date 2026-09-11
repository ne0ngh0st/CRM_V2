<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Services\Totvs\ClientesLookup;
use App\Services\Totvs\Normalizador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A chave que identifica um cliente na hora de GRAVAR.
 *
 * ⚠️ Nasceu de um defeito real, encontrado em 11/09/2026 ao rodar
 * `legado:import-clientes` sobre uma base carregada pelo `totvs:import-clientes`:
 * **8.408 clientes duplicados**, sem erro nenhum.
 *
 * As duas origens discordam da largura do campo — o banco guarda `008710` (herança do
 * espelho do v1) e o espelho/relatório de hoje devolve `8710`. Como `upsert` casa por
 * igualdade literal, a forma nova entra como cliente NOVO em vez de atualizar o que já
 * existe. O `totvs:import-clientes` tinha a proteção; o `legado:import-clientes`, que
 * carregava sua própria cópia da regra, não tinha (Regra de ouro nº 8).
 *
 * Cliente duplicado não é cosmético: racha a carteira do vendedor, porque metade do
 * histórico fica pendurada na linha que ninguém abre.
 */
class ImportClienteChaveTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(string $codCliente, string $loja, string $nome = 'ACME LTDA'): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codCliente,
            'loja' => $loja,
            'razao_social' => $nome,
            'cod_vendedor' => '010617',
        ]);
    }

    #[Test]
    public function zero_a_esquerda_nao_distingue_cliente(): void
    {
        $this->assertSame(
            Normalizador::chaveCliente('001014', '008710'),
            Normalizador::chaveCliente('1014', '8710'),
            'Se estas duas chaves divergirem, o mesmo cliente vira dois registros.'
        );
    }

    #[Test]
    public function o_lookup_devolve_a_forma_como_esta_gravada(): void
    {
        $this->cliente('001014', '008710');

        $mapa = ClientesLookup::formaGravadaPorChave();
        $chaveDoArquivo = Normalizador::chaveCliente('1014', '8710');

        $this->assertArrayHasKey($chaveDoArquivo, $mapa);

        /*
         * O importador usa ISTO para trocar a forma que veio do arquivo pela forma que
         * já está no banco, antes do upsert. Devolver `['1014','8710']` aqui seria o
         * bug de 11/09 de volta.
         */
        $this->assertSame(['001014', '008710'], $mapa[$chaveDoArquivo]);
    }

    #[Test]
    public function gravar_com_a_forma_do_arquivo_criaria_um_segundo_cliente(): void
    {
        $this->cliente('001014', '008710');

        // Demonstra o defeito: sem passar pelo lookup, o upsert não reconhece a linha.
        Cliente::upsert(
            [['cod_cliente' => '1014', 'loja' => '8710', 'razao_social' => 'ACME LTDA', 'cod_vendedor' => '010617']],
            ['cod_cliente', 'loja'],
            ['razao_social']
        );

        $this->assertSame(2, Cliente::count(), 'É exatamente assim que nasceram os 8.408 duplicados.');

        // E o caminho correto — o que os dois importadores fazem hoje — não duplica.
        Cliente::query()->delete();
        $this->cliente('001014', '008710');

        $mapa = ClientesLookup::formaGravadaPorChave();
        [$cod, $loja] = $mapa[Normalizador::chaveCliente('1014', '8710')];

        Cliente::upsert(
            [['cod_cliente' => $cod, 'loja' => $loja, 'razao_social' => 'ACME LTDA ATUALIZADA', 'cod_vendedor' => '010617']],
            ['cod_cliente', 'loja'],
            ['razao_social']
        );

        $this->assertSame(1, Cliente::count());
        $this->assertSame('ACME LTDA ATUALIZADA', Cliente::first()->razao_social);
    }

    #[Test]
    public function lojas_realmente_diferentes_continuam_separadas(): void
    {
        // O contraveneno do teste acima: normalizar não pode FUNDIR clientes distintos.
        // '0051' e '0001' são lojas diferentes do mesmo código, e o TOTVS também usa
        // lojas não numéricas ('E001'), que zero à esquerda nenhum afeta.
        $this->cliente('039627', '0051', 'MINISTERIO - LOJA 51');
        $this->cliente('039627', '0001', 'MINISTERIO - LOJA 1');
        $this->cliente('039627', 'E001', 'MINISTERIO - LOJA E001');

        $this->assertCount(3, ClientesLookup::formaGravadaPorChave());
    }
}
