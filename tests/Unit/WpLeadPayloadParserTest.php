<?php

namespace Tests\Unit;

use App\Services\Marketing\WpLeadPayloadParser;
use PHPUnit\Framework\TestCase;

class WpLeadPayloadParserTest extends TestCase
{
    private WpLeadPayloadParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new WpLeadPayloadParser;
    }

    public function test_json_valido_vira_parsed(): void
    {
        $parsed = $this->parser->parsearCorpo('{"nome":"Ana","email":"ana@x.com"}', []);

        $this->assertSame('Ana', $parsed['nome']);
        $this->assertSame('ana@x.com', $parsed['email']);
    }

    public function test_json_invalido_cai_no_post(): void
    {
        $parsed = $this->parser->parsearCorpo('not-json', ['nome' => 'Bruno']);

        $this->assertSame('Bruno', $parsed['nome']);
    }

    public function test_fields_sobe_pro_mesmo_nivel_sem_apagar_o_resto(): void
    {
        $parsed = $this->parser->parsearCorpo(json_encode([
            'form' => 'contato',
            'fields' => [
                'nome' => 'Carla',
                'email' => 'carla@x.com',
                'nested' => ['ignorado' => true],
            ],
        ]), []);

        $this->assertSame('contato', $parsed['form']);
        $this->assertSame('Carla', $parsed['nome']);
        $this->assertSame('carla@x.com', $parsed['email']);
        $this->assertIsArray($parsed['fields']);
        $this->assertArrayNotHasKey('nested', $parsed);
    }

    public function test_extrai_aliases_comuns_de_form_wp(): void
    {
        $campos = $this->parser->extrairCampos([
            'your-name' => 'Diego',
            'your-email' => 'diego@x.com',
            'whatsapp' => '11999998888',
            'razao-social' => 'Autopel Ltda',
        ]);

        $this->assertSame('Diego', $campos['nome']);
        $this->assertSame('diego@x.com', $campos['email']);
        $this->assertSame('11999998888', $campos['telefone']);
        $this->assertSame('Autopel Ltda', $campos['empresa']);
    }

    public function test_csv_usa_coluna_de_envio_como_data(): void
    {
        $data = $this->parser->dataDoCsv([
            'Nome' => 'Eva',
            'Submitted' => '2024-03-15 10:30:00',
        ]);

        $this->assertNotNull($data);
        $this->assertSame('2024-03-15', $data->toDateString());
        $this->assertSame('America/Sao_Paulo', $data->timezone->getName());
    }

    public function test_csv_sem_coluna_de_data_devolve_null(): void
    {
        $this->assertNull($this->parser->dataDoCsv([
            'Nome' => 'Eva',
            'Email' => 'eva@x.com',
        ]));
    }
}
