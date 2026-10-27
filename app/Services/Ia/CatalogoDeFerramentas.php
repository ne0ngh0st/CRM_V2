<?php

namespace App\Services\Ia;

use App\Services\Ia\Ferramentas\AbrirChamado;
use App\Services\Ia\Ferramentas\BuscarCliente;
use App\Services\Ia\Ferramentas\ConsultarPedido;
use App\Services\Ia\Ferramentas\FrescorDosDados;
use App\Services\Ia\Ferramentas\MinhaMeta;
use App\Services\Ia\Ferramentas\QuemCuidaDoCliente;
use App\Services\Ia\Ferramentas\ResumoCliente;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A whitelist do que o assistente pode fazer. Ferramenta nova entra AQUI.
 *
 * ⚠️ O nome chamado pelo modelo vem da resposta da API e só é executado se estiver nesta
 * lista — mesmo princípio da whitelist de ordenação da Carteira.
 *
 * ⚠️ A ORDEM importa para o cache de prompt: as definições vão no começo de toda
 * requisição, e mudar a ordem (ou a descrição) invalida o cache de todo mundo uma vez.
 */
final class CatalogoDeFerramentas
{
    /** @var list<class-string<Ferramenta>> */
    public const FERRAMENTAS = [
        ConsultarPedido::class,
        BuscarCliente::class,
        ResumoCliente::class,
        QuemCuidaDoCliente::class,
        MinhaMeta::class,
        FrescorDosDados::class,
        AbrirChamado::class,
    ];

    /** @var array<string, Ferramenta>|null */
    private ?array $instancias = null;

    /** @return array<string, Ferramenta> */
    public function todas(): array
    {
        if ($this->instancias === null) {
            $this->instancias = [];
            foreach (self::FERRAMENTAS as $classe) {
                $ferramenta = app($classe);
                $this->instancias[$ferramenta->nome()] = $ferramenta;
            }
        }

        return $this->instancias;
    }

    /** @return list<array<string, mixed>> definições no formato da API */
    public function definicoes(): array
    {
        return array_values(array_map(fn (Ferramenta $f) => [
            'name' => $f->nome(),
            'description' => $f->descricao(),
            'input_schema' => $f->esquema(),
            'strict' => true,
        ], $this->todas()));
    }

    public function rotulo(string $nome): string
    {
        return ($this->todas()[$nome] ?? null)?->rotuloProgresso() ?? 'Consultando…';
    }

    /**
     * Executa e devolve o `tool_result` pronto para o histórico.
     *
     * Erro de ferramenta não derruba a resposta: vira `is_error` e o modelo explica ao
     * usuário que não conseguiu consultar. O detalhe técnico fica no log, não no chat.
     *
     * @param  array<string, mixed>  $chamada  bloco `tool_use` da resposta
     * @return array<string, mixed>
     */
    public function executar(ContextoIa $contexto, array $chamada): array
    {
        $resultado = ['type' => 'tool_result', 'tool_use_id' => $chamada['id']];
        $ferramenta = $this->todas()[$chamada['name'] ?? ''] ?? null;

        if ($ferramenta === null) {
            return $resultado + ['content' => 'Ferramenta desconhecida.', 'is_error' => true];
        }

        try {
            $saida = $ferramenta->executar($contexto, is_array($chamada['input'] ?? null) ? $chamada['input'] : []);

            return $resultado + ['content' => json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        } catch (Throwable $e) {
            Log::warning('ia: ferramenta falhou', ['ferramenta' => $ferramenta->nome(), 'erro' => $e->getMessage()]);

            return $resultado + ['content' => 'Não foi possível consultar agora. Erro interno do CRM.', 'is_error' => true];
        }
    }
}
