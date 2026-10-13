<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\Segmento;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alerta de vínculo suspeito (`VinculosSuspeitos`). O caso real: o código da ESTAPAR
 * (estacionamentos) ligado ao CARREFOUR e ao MATEUS inflava o Carrefour na tela.
 */
class VinculosSuspeitosTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $mercados;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mercados = Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
    }

    /** Um código com `$n` filiais, todas com a mesma razão social. */
    private function codigo(string $cod, int $n, string $razao): void
    {
        foreach (range(1, $n) as $i) {
            Cliente::create([
                'cod_cliente' => $cod,
                'loja' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'cnpj' => '60.537.263/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'-'.substr($cod, -2),
                'razao_social' => "{$razao} {$i}",
                'cod_segmento' => '101',
                'estado' => 'SP',
                'data_ultima_compra' => now()->subDays(5),
            ]);
        }
    }

    private function rede(string $nome, array $codigos, ?string $apelidos = null, int $ordem = 1): ContaEstrategica
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->mercados->id, 'nome' => $nome, 'apelidos' => $apelidos, 'ordem' => $ordem]);
        app(ClientesDaConta::class)->sincronizarVinculos($conta, array_map(fn ($c) => ['tipo' => 'cliente', 'codigo' => $c], $codigos));

        return $conta;
    }

    private function suspeitosDe(string $rede): array
    {
        app(UniversoDoSegmento::class)->reconstruir();

        return collect(app(UniversoDoSegmento::class)->lista('101')['linhas']->items())
            ->firstWhere('nome', $rede)['suspeitos'];
    }

    public function test_codigo_sem_o_nome_da_rede_e_suspeito(): void
    {
        // 12 estacionamentos, nenhum com "CARREFOUR" no nome.
        $this->codigo('000242', 12, 'ALLPARK EMPREENDIMENTOS');
        $this->rede('CARREFOUR', ['000242']);

        $suspeitos = $this->suspeitosDe('CARREFOUR');

        $this->assertSame('sem_nome', $suspeitos[0]['motivo']);
        $this->assertSame('000242', $suspeitos[0]['codigo']);
        $this->assertStringContainsString('0 de 12', $suspeitos[0]['texto']);
    }

    public function test_codigo_com_o_nome_da_rede_nao_e_suspeito(): void
    {
        $this->codigo('004535', 12, 'CARREFOUR COMERCIO E INDUSTRIA');
        $this->rede('CARREFOUR', ['004535']);

        $this->assertSame([], $this->suspeitosDe('CARREFOUR'));
    }

    public function test_apelido_conta_como_nome_da_rede(): void
    {
        // Rede ligada pela razão social: o nome da rede não aparece nas filiais.
        $this->codigo('001256', 12, 'SENDAS DISTRIBUIDORA');
        $this->rede('ASSAÍ ATACADISTA', ['001256'], 'Sendas');

        $this->assertSame([], $this->suspeitosDe('ASSAÍ ATACADISTA'));
    }

    public function test_poucas_filiais_nao_disparam_a_regra_do_nome(): void
    {
        $this->codigo('000111', 3, 'OUTRO NOME');
        $this->rede('CARREFOUR', ['000111']);

        $this->assertSame([], $this->suspeitosDe('CARREFOUR'));
    }

    public function test_codigo_ligado_a_duas_redes_e_suspeito_nas_duas(): void
    {
        $this->codigo('000300', 2, 'LOJA QUALQUER');
        $this->rede('CARREFOUR', ['000300'], null, 1);
        $this->rede('MATEUS SUPERMERCADOS', ['000300'], null, 2);

        $carrefour = $this->suspeitosDe('CARREFOUR');
        $mateus = $this->suspeitosDe('MATEUS SUPERMERCADOS');

        $this->assertSame('compartilhado', $carrefour[0]['motivo']);
        $this->assertSame('também ligado a MATEUS SUPERMERCADOS', $carrefour[0]['texto']);
        $this->assertSame('também ligado a CARREFOUR', $mateus[0]['texto']);
    }
}
