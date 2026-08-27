<?php

namespace Tests\Unit\Cache;

use App\Services\Cache\ChaveEscopo;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Trava a normalização das chaves de cache.
 *
 * Se duas descrições do mesmo escopo gerarem chaves diferentes, o cache warming da Fase 3
 * grava num lugar e o controller lê de outro — sem erro, sem log, só "continua lento".
 * É o modo de falha mais silencioso do plano de performance, e é o que estes testes pegam.
 */
class ChaveEscopoTest extends TestCase
{
    public function test_ordem_dos_codigos_nao_muda_a_chave(): void
    {
        $a = ChaveEscopo::deCodVendedores(['000123', '000456'])->para('bloco');
        $b = ChaveEscopo::deCodVendedores(['000456', '000123'])->para('bloco');

        $this->assertSame($a, $b, 'A ordem da lista de vendedores mudou a chave — foi exatamente esse o bug que a classe existe pra impedir.');
    }

    public function test_codigo_repetido_nao_muda_a_chave(): void
    {
        $a = ChaveEscopo::deCodVendedores(['000123', '000456'])->para('bloco');
        $b = ChaveEscopo::deCodVendedores(['000123', '000456', '000123'])->para('bloco');

        $this->assertSame($a, $b);
    }

    public function test_int_e_string_do_mesmo_id_geram_a_mesma_chave(): void
    {
        // usuarioIds vem de pluck('id') (int); um chamador pode passar string.
        $a = ChaveEscopo::deUsuarioIds([7, 12])->para('bloco');
        $b = ChaveEscopo::deUsuarioIds(['7', '12'])->para('bloco');

        $this->assertSame($a, $b);
    }

    public function test_escopos_diferentes_geram_chaves_diferentes(): void
    {
        $empresa = ChaveEscopo::deCodVendedores(null)->para('bloco');
        $vazio = ChaveEscopo::deCodVendedores([])->para('bloco');
        $um = ChaveEscopo::deCodVendedores(['000123'])->para('bloco');

        $this->assertCount(3, array_unique([$empresa, $vazio, $um]));
    }

    public function test_empresa_inteira_e_escopo_vazio_nao_se_confundem(): void
    {
        // `null` = admin sem filtro (vê tudo). `[]` = assistente/vendedor sem código
        // (não vê nada). Colidir aqui mostraria a empresa inteira pra quem não pode ver.
        $this->assertNotSame(
            ChaveEscopo::deCodVendedores(null)->para('bloco'),
            ChaveEscopo::deCodVendedores([])->para('bloco'),
        );
    }

    public function test_tipos_de_escopo_diferentes_nao_colidem(): void
    {
        $this->assertNotSame(
            ChaveEscopo::deCodVendedores(['7'])->para('bloco'),
            ChaveEscopo::deUsuarioIds([7])->para('bloco'),
        );
    }

    public function test_blocos_diferentes_geram_chaves_diferentes(): void
    {
        $escopo = ChaveEscopo::deCodVendedores(null);

        $this->assertNotSame($escopo->para('carteira-segmento'), $escopo->para('pedidos-atencao'));
    }

    public function test_extras_entram_na_chave_e_independem_da_ordem_de_declaracao(): void
    {
        $escopo = ChaveEscopo::deCodVendedores(null);

        $this->assertNotSame($escopo->para('b', ['ano' => 2026]), $escopo->para('b', ['ano' => 2025]));
        $this->assertSame(
            $escopo->para('b', ['ano' => 2026, 'mes' => 3]),
            $escopo->para('b', ['mes' => 3, 'ano' => 2026]),
        );
    }

    public function test_lista_longa_vira_hash_estavel(): void
    {
        $muitos = array_map(fn (int $i) => str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 60));

        $chave = ChaveEscopo::deCodVendedores($muitos)->para('bloco');
        $mesma = ChaveEscopo::deCodVendedores(array_reverse($muitos))->para('bloco');

        $this->assertSame($chave, $mesma);
        $this->assertStringContainsString(':h:', $chave, 'Lista longa deveria virar hash.');
        $this->assertLessThan(120, strlen($chave));
    }

    public function test_para_do_dia_muda_quando_o_dia_vira(): void
    {
        $escopo = ChaveEscopo::deCodVendedores(null);

        Carbon::setTestNow('2026-08-27 23:59:00');
        $hoje = $escopo->paraDoDia('carteira-segmento');

        Carbon::setTestNow('2026-08-28 00:01:00');
        $amanha = $escopo->paraDoDia('carteira-segmento');

        Carbon::setTestNow();

        $this->assertNotSame(
            $hoje,
            $amanha,
            'Blocos que classificam por "dias desde a última compra" precisam de chave nova a cada dia.',
        );
    }
}
