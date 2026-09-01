<?php

namespace App\Services\Marketing;

use App\Models\MarketingWpFormulario;

/**
 * Dono comercial do lead do site: uma linha por formulário, fallback `*`.
 *
 * Não mora em config/env. Form novo = INSERT em marketing_wp_formularios
 * (identificador = id do CF7, slug do WPForms, rótulo do CSV…). Sem linha
 * específica, cai no `*` (hoje 010617).
 */
class WpLeadFormularioResolver
{
    /**
     * @param  array<string, mixed>  $parsed
     */
    public function resolver(array $parsed, ?string $rotuloCsv = null): ?MarketingWpFormulario
    {
        $identificador = $this->identificadorDoPayload($parsed, $rotuloCsv);

        if ($identificador !== null && $identificador !== MarketingWpFormulario::IDENTIFICADOR_PADRAO) {
            $especifico = $this->ativoPorIdentificador($identificador);
            if ($especifico) {
                return $especifico;
            }
        }

        return $this->ativoPorIdentificador(MarketingWpFormulario::IDENTIFICADOR_PADRAO);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function identificadorDoPayload(array $parsed, ?string $rotuloCsv = null): ?string
    {
        if ($rotuloCsv !== null && trim($rotuloCsv) !== '') {
            return trim($rotuloCsv);
        }

        foreach (['_wpcf7', 'form_id', 'formulario', 'form_name', 'form-name', 'form'] as $chave) {
            if (! isset($parsed[$chave]) || ! is_scalar($parsed[$chave])) {
                continue;
            }
            $valor = trim((string) $parsed[$chave]);
            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    private function ativoPorIdentificador(string $identificador): ?MarketingWpFormulario
    {
        return MarketingWpFormulario::query()
            ->where('identificador', $identificador)
            ->where('ativo', true)
            ->first();
    }
}
