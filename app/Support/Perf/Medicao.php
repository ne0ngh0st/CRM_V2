<?php

namespace App\Support\Perf;

/**
 * Resultado de uma medição feita por {@see ContadorDeQueries::medir()}.
 *
 * ⚠️ Nem toda métrica daqui vale o mesmo. Ver docs/performance.md — no ambiente local
 * (Docker sobre WSL2, bind-mount Windows, `php artisan serve` single-process) o
 * wall-clock é ruído; contagem de query e bytes de payload são determinísticos e
 * valem tanto aqui quanto na AWS.
 */
final readonly class Medicao
{
    public function __construct(
        /** Nº de queries executadas. DETERMINÍSTICO — é a métrica primária. */
        public int $queries,
        /** Soma do tempo relatado pelo MySQL, em ms. Semi-confiável: mede o banco, não a ponte de I/O. */
        public float $msSql,
        /** Tempo de parede, em ms. ⚠️ RUÍDO no ambiente local — nunca usar pra aceitar/rejeitar meta. */
        public float $msWall,
        /** Pico de memória do PHP durante a ação, em bytes. DETERMINÍSTICO. */
        public int $picoMemoriaBytes,
        /** Tamanho do corpo da resposta, em bytes (0 se a ação não devolveu Response). DETERMINÍSTICO. */
        public int $bytesPayload,
        /** SQLs capturados, só quando pedido explicitamente. @var list<string> */
        public array $sqls,
        /** O que a closure medida devolveu — normalmente a Response. */
        public mixed $resultado,
    ) {}

    public function picoMemoriaMb(): float
    {
        return round($this->picoMemoriaBytes / 1024 / 1024, 1);
    }

    public function payloadKb(): float
    {
        return round($this->bytesPayload / 1024, 1);
    }
}
