<?php

namespace Tests\Unit;

use App\Services\Totvs\Normalizador;
use PHPUnit\Framework\TestCase;

/**
 * Trava a regra de codificação do dado do TOTVS.
 *
 * Os fixtures NÃO são inventados: cada um saiu do `199 - ULTIMO FATURAMENTO` de produção
 * em 2026-09-08, o arquivo cujo byte `A0` solto derrubou a rodada manual com
 * `1366 Incorrect string value` ao gravar `grupos_cliente.nome`.
 *
 * ⚠️ O caso que mais importa aqui é `test_preserva_utf8_valido_num_campo_misto`: é ele
 * que reprova a correção óbvia e errada — converter o campo inteiro de cp1252 — que
 * passaria em todos os outros casos deste arquivo.
 */
class NormalizadorEncodingTest extends TestCase
{
    public function test_recupera_nbsp_cp1252_solto_como_espaco(): void
    {
        // Exatamente como veio do relatório: "POSTOS" + byte A0 cru + "LAURINDAO".
        $bruto = "POSTOS\xA0LAURINDAO";

        $this->assertFalse(mb_check_encoding($bruto, 'UTF-8'), 'o fixture tem que ser inválido, senão o teste não prova nada');
        $this->assertSame('POSTOS LAURINDAO', Normalizador::textoUtf8($bruto));
        $this->assertTrue(mb_check_encoding(Normalizador::textoUtf8($bruto), 'UTF-8'));
    }

    public function test_preserva_utf8_valido_num_campo_misto(): void
    {
        // `Ç` legítimo (C3 87) ao lado de um NBSP cru. Uma conversão em bloco de cp1252
        // devolveria `AÃ‡O`, e os 8.587 caracteres acentuados do cadastro de clientes
        // entrariam mojibake no banco sem nenhum erro aparecer.
        $bruto = "A\xC3\x87O\xA0INOX";

        $this->assertSame('AÇO INOX', Normalizador::textoUtf8($bruto));
    }

    public function test_normaliza_nbsp_que_ja_estava_em_utf8_valido(): void
    {
        // C2 A0 é NBSP bem-formado: o MySQL aceitaria, e por isso o defeito seria
        // invisível — dois grupos de mesmo nome na tela, um deles impossível de casar.
        $bruto = "POSTOS\xC2\xA0LAURINDAO";

        $this->assertTrue(mb_check_encoding($bruto, 'UTF-8'));
        $this->assertSame('POSTOS LAURINDAO', Normalizador::textoUtf8($bruto));
    }

    public function test_recupera_acentuada_cp1252_solta_em_vez_de_descartar(): void
    {
        // Ainda não apareceu nos relatórios, mas é o mesmo defeito de origem. Uma
        // substituição cega por `?` daria `MOA`/`MO?A`; o certo é ler como cp1252.
        $this->assertSame('MOÇA', Normalizador::textoUtf8("MO\xC7A"));
    }

    public function test_texto_limpo_atravessa_intacto(): void
    {
        $this->assertSame('SUPERMERCADO BOM PREÇO', Normalizador::textoUtf8('SUPERMERCADO BOM PREÇO'));
        $this->assertSame('', Normalizador::textoUtf8(''));
        $this->assertSame('', Normalizador::textoUtf8(null));
    }

    public function test_nbsp_no_fim_do_campo_passa_a_ser_aparavel(): void
    {
        // Veio assim de produção: `americanas@invoisys.io` + A0 + espaços. O `trim` do
        // PHP não morde NBSP, então sem esta regra o e-mail entraria com lixo no fim.
        $this->assertSame('americanas@invoisys.io', trim(Normalizador::textoUtf8("americanas@invoisys.io\xA0        ")));
    }

    public function test_nao_quebra_multibyte_de_tres_bytes(): void
    {
        // O `META VENDA` traz travessão (E2 80 93) em 55 mil posições.
        $this->assertSame('VENDA – SP', Normalizador::textoUtf8("VENDA \xE2\x80\x93 SP"));
    }
}
