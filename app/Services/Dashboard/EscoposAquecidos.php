<?php

namespace App\Services\Dashboard;

use App\Models\VendedorPerfil;

/**
 * Quais escopos o job de cache warming precisa aquecer.
 *
 * ⚠️ A ASSIMETRIA QUE FAZ ISTO SER BARATO:
 * o escopo caro pertence a pouquíssimos usuários. Só admin e diretor SEM filtro geram o
 * escopo "empresa inteira" (~2.000 ms no carteiraSegmento, 862 ms no faturamento), e os
 * supervisores geram uma dúzia de escopos de equipe. A maioria das ~200 pessoas é
 * vendedor, e o escopo de vendedor já é naturalmente rápido — 6-9 ms para 283 clientes,
 * porque o próprio filtro já corta a tabela.
 *
 * Resultado: aquecer ~16 escopos cobre praticamente toda a dor, e o job roda em segundos.
 */
class EscoposAquecidos
{
    public function __construct(private readonly DashboardScopeResolver $resolver) {}

    /**
     * @return list<array{rotulo: string, codVendedores: array<string>|null, usuarioIds: array<int>}>
     */
    public function listar(): array
    {
        $escopos = [];

        if (config('perf.escopos_aquecidos.empresa', true)) {
            // O mais caro de todos, e o único que `resolve()` devolve como `null`.
            $escopos[] = $this->montar('empresa inteira', null);
        }

        if (config('perf.escopos_aquecidos.supervisores', true)) {
            foreach ($this->codigosDeSupervisores() as $codSuper) {
                $equipe = VendedorPerfil::query()
                    ->where('cod_super', $codSuper)
                    ->pluck('cod_vendedor')
                    ->all();

                if ($equipe === []) {
                    continue;
                }

                // Cobre DOIS casos de uso de uma vez: o supervisor logado e o admin com
                // ?visao_supervisor=X. `resolve()` devolve exatamente o mesmo array nos
                // dois, e a chave é derivada do conteúdo do escopo, não de quem pediu —
                // é o dividendo de chavear por escopo em vez de por user_id.
                $escopos[] = $this->montar("equipe do supervisor {$codSuper}", $equipe);
            }
        }

        /*
         * ⚠️ Vendedores individuais NÃO entram, e isto é deliberado (config
         * `escopos_aquecidos.vendedores`, default false). São ~200 escopos que custam
         * 6-9 ms cada: aquecê-los multiplicaria o trabalho do worker por dez para
         * economizar 9 ms por pessoa. Se alguém for "melhorar" o job um dia, que veja
         * a decisão escrita antes de mexer.
         */
        if (config('perf.escopos_aquecidos.vendedores', false)) {
            foreach (VendedorPerfil::query()->pluck('cod_vendedor')->unique() as $cod) {
                $escopos[] = $this->montar("vendedor {$cod}", [$cod]);
            }
        }

        return $escopos;
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return array{rotulo: string, codVendedores: array<string>|null, usuarioIds: array<int>}
     */
    private function montar(string $rotulo, ?array $codVendedores): array
    {
        return [
            'rotulo' => $rotulo,
            'codVendedores' => $codVendedores,
            // Vem do resolver, não montado aqui: se a regra divergir, o job aquece uma
            // chave que nenhuma requisição procura.
            'usuarioIds' => $this->resolver->usuarioIdsDoEscopo($codVendedores),
        ];
    }

    /** @return array<string> */
    private function codigosDeSupervisores(): array
    {
        return VendedorPerfil::query()
            ->whereNotNull('cod_super')
            ->where('cod_super', '!=', '')
            ->distinct()
            ->pluck('cod_super')
            ->all();
    }
}
