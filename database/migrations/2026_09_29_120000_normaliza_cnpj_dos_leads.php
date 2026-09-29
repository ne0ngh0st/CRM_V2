<?php

use App\Services\Totvs\Normalizador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Põe o CNPJ de todo lead no formato dos clientes (`12.345.678/0001-90`).
 *
 * Lead manual e lead do site guardavam o que foi digitado; o import do TOTVS já
 * normalizava. A partir de 2026-09-29 o mutator de `Lead::cnpj` cuida do que entra —
 * esta migration arruma o que já estava lá, para o selo "Já é cliente" da aba Leads
 * enxergar também os leads antigos.
 *
 * Usa a MESMA função do mutator e dos imports (`Normalizador::documento`), nunca uma
 * cópia da regra em SQL: com duas versões, o que a migration grava e o que o mutator
 * grava poderiam divergir num caso de borda.
 *
 * ⚠️ Não destrutiva: só muda pontuação. Valor que não tem 11 nem 14 dígitos fica só com
 * os dígitos (é o que o mutator faria), e vazio vira NULL. Idempotente — rodar de novo
 * não mexe em nada, porque só atualiza a linha cujo valor muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leads')
            ->whereNotNull('cnpj')
            ->select(['id', 'cnpj'])
            ->orderBy('id')
            ->chunkById(1000, function ($linhas) {
                foreach ($linhas as $linha) {
                    $normalizado = Normalizador::documento($linha->cnpj);

                    if ($normalizado !== $linha->cnpj) {
                        DB::table('leads')->where('id', $linha->id)->update(['cnpj' => $normalizado]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Sem volta: o formato original digitado não é guardado, e não faz falta.
    }
};
