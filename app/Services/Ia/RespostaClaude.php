<?php

namespace App\Services\Ia;

/**
 * Uma resposta da API já acumulada, no formato em que volta para o histórico.
 *
 * ⚠️ `conteudo` é a lista de blocos EXATAMENTE como a API devolveu (thinking, text,
 * tool_use, fallback…). Ela entra no histórico sem edição — ver o aviso da migration
 * `create_ia_tables`.
 */
final class RespostaClaude
{
    /**
     * @param  list<array<string, mixed>>  $conteudo
     */
    public function __construct(
        public readonly array $conteudo,
        public readonly ?string $stopReason,
        public readonly string $modelo,
        public readonly int $tokensEntrada = 0,
        public readonly int $tokensSaida = 0,
        public readonly int $tokensCacheLeitura = 0,
        public readonly int $tokensCacheEscrita = 0,
    ) {}

    /** @return list<array<string, mixed>> */
    public function chamadasDeFerramenta(): array
    {
        return array_values(array_filter($this->conteudo, fn (array $b) => ($b['type'] ?? null) === 'tool_use'));
    }

    public function texto(): string
    {
        $partes = [];
        foreach ($this->conteudo as $bloco) {
            if (($bloco['type'] ?? null) === 'text' && trim((string) ($bloco['text'] ?? '')) !== '') {
                $partes[] = trim($bloco['text']);
            }
        }

        return implode("\n\n", $partes);
    }
}
