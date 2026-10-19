<?php

namespace Tests\Unit\VisaoDiretor;

use App\Services\VisaoDiretor\ExtratorDeFaturamento;
use PHPUnit\Framework\TestCase;

/** O que a página declara como faturamento. O que não é número declarado não vira valor. */
class ExtratorDeFaturamentoTest extends TestCase
{
    private ExtratorDeFaturamento $extrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extrator = new ExtratorDeFaturamento;
    }

    public function test_le_o_ano_mais_recente_com_a_escala(): void
    {
        $this->assertSame(
            ['valor' => 1_200_000_000, 'ano' => 2024],
            $this->extrator->doTexto('A empresa faturou R$ 800 milhões em 2022. Em 2024 o faturamento foi de R$ 1,2 bilhão.'),
        );
        $this->assertSame(
            ['valor' => 12_500_000, 'ano' => 2023],
            $this->extrator->doTexto('Faturamento de R$ 12,5 milhões em 2023.'),
        );
        $this->assertSame(
            ['valor' => 4_500_000, 'ano' => 2022],
            $this->extrator->doTexto('Receita bruta de R$ 4.500.000 em 2022.'),
        );
        $this->assertSame(
            ['valor' => 800_000, 'ano' => 2021],
            $this->extrator->doTexto('A rede faturou 800 mil em 2021.'),
        );
    }

    public function test_rejeita_capital_media_e_numero_sem_ano(): void
    {
        $this->assertNull($this->extrator->doTexto('Capital social de R$ 10 milhões em 2020.'));
        $this->assertNull($this->extrator->doTexto('Faturamento médio do setor é R$ 2 milhões em 2024.'));
        $this->assertNull($this->extrator->doTexto('O faturamento cresceu forte nos últimos anos.'));
        $this->assertNull($this->extrator->doTexto('Faturamento de R$ 500 em 2024.'));
    }

    public function test_entre_paginas_fica_o_ano_mais_recente_e_a_url(): void
    {
        $achado = $this->extrator->extrair([
            ['url' => 'https://velho.example/a', 'description' => 'Faturamento de R$ 3 milhões em 2021.'],
            ['url' => 'https://novo.example/b', 'title' => 'Balanço', 'description' => 'Receita bruta de R$ 9 milhões em 2024.'],
        ]);

        $this->assertSame(['valor' => 9_000_000, 'ano' => 2024, 'url' => 'https://novo.example/b'], $achado);
    }
}
