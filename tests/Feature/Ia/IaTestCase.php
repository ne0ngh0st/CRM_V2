<?php

namespace Tests\Feature\Ia;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Ia\ClienteClaude;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ClienteClaudeRoteirizado;
use Tests\TestCase;

/**
 * Base dos testes do assistente de IA: API de mentira, papéis semeados e fixtures.
 *
 * A fila roda `sync` na suíte (phpunit.xml), então o POST de uma pergunta já executa o
 * job e o loop inteiro antes de responder — o teste lê o resultado direto no banco.
 */
abstract class IaTestCase extends TestCase
{
    use RefreshDatabase;

    protected ClienteClaudeRoteirizado $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['ia.habilitado' => true, 'ia.usuarios_liberados' => [], 'ia.chamados_para' => 'suporte@autopel.com']);
    }

    /** @param list<mixed> $roteiro */
    protected function roteiro(array $roteiro): ClienteClaudeRoteirizado
    {
        $this->claude = new ClienteClaudeRoteirizado($roteiro);
        $this->app->instance(ClienteClaude::class, $this->claude);

        return $this->claude;
    }

    protected function usuario(string $role, ?string $codVendedor = null, ?string $codSuper = null, bool $liberado = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        if ($codVendedor) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor, 'cod_super' => $codSuper]);
        }

        if ($liberado) {
            config(['ia.usuarios_liberados' => [...config('ia.usuarios_liberados'), mb_strtolower($user->email)]]);
        }

        return $user;
    }

    protected function cliente(array $atributos = []): Cliente
    {
        return Cliente::create([
            'cod_cliente' => '000123',
            'loja' => '01',
            'cnpj' => '11.222.333/0001-81',
            'razao_social' => 'MERCADO BOM PRECO LTDA',
            'cod_vendedor' => '000010',
            ...$atributos,
        ]);
    }

    protected function pedido(array $atributos = []): Pedido
    {
        return Pedido::create([
            'numero_pedido' => '992086',
            'cod_vendedor' => '000010',
            'data_pedido' => today()->subDays(5),
            'data_previsao_faturamento' => today()->subDay(),
            'valor_total' => 1500.50,
            'historico_totvs' => 'COM BLOQUEIO DE ESTOQUE',
            ...$atributos,
        ]);
    }

    /** Pergunta pelo endpoint real, como o painel faz. */
    protected function perguntar(User $user, string $texto, ?int $conversaId = null, ?string $tela = '/carteira')
    {
        return $this->actingAs($user)->postJson(route('ia.enviar'), [
            'texto' => $texto,
            'conversa_id' => $conversaId,
            'tela' => $tela,
        ]);
    }

    /**
     * O `tool_result` que o orquestrador devolveu ao modelo na volta `$volta` (0 = a
     * primeira resposta de ferramenta), decodificado.
     *
     * @return array<string, mixed>
     */
    protected function resultadoDaFerramenta(int $requisicao = 1): array
    {
        $mensagens = $this->claude->requisicoes[$requisicao]['messages'];
        $ultima = end($mensagens);
        $this->assertSame('user', $ultima['role']);
        $this->assertSame('tool_result', $ultima['content'][0]['type']);

        return json_decode($ultima['content'][0]['content'], true) ?? ['_texto' => $ultima['content'][0]['content']];
    }
}
