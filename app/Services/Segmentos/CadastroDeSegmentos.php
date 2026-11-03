<?php

namespace App\Services\Segmentos;

use App\Models\Segmento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O cadastro de segmentos (Visão Gestor → Segmentos, admin + diretor).
 *
 * ⚠️ O CÓDIGO NÃO SE EDITA. É a chave do TOTVS: `clientes.cod_segmento` aponta para ele,
 * e mudá-lo aqui desligaria da lista todos os clientes do segmento, sem erro nenhum.
 *
 * ⚠️ RENOMEAR LEVA OS LEADS JUNTO: `leads.segmento` guarda o NOME, não o código (é como
 * o import de prospecção grava). Sem isso, os leads ficariam com o nome velho e o filtro
 * de segmento da tela de Leads mostraria os dois.
 *
 * ⚠️ Excluir só o que ninguém usa. Segmento com cliente voltaria no próximo import como
 * "código sem nome", e `segmentos_vendedor` apagaria em cascata quem o atende.
 */
class CadastroDeSegmentos
{
    /**
     * Linhas da tabela, com o uso de cada segmento. Sem cache: a tela existe para editar,
     * e o número tem que mudar no request seguinte.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $clientes = DB::table('clientes')->selectRaw('cod_segmento, COUNT(*) AS total')
            ->whereNotNull('cod_segmento')->groupBy('cod_segmento')->pluck('total', 'cod_segmento');
        $leads = DB::table('leads')->selectRaw('segmento, COUNT(*) AS total')
            ->where('status', '!=', 'excluido')->whereNotNull('segmento')->groupBy('segmento')->pluck('total', 'segmento');
        $pessoas = DB::table('segmentos_vendedor')->selectRaw('segmento_id, COUNT(DISTINCT cod_vendedor) AS total')
            ->groupBy('segmento_id')->pluck('total', 'segmento_id');

        return Segmento::query()
            ->orderBy('nome')
            ->get(['id', 'codigo', 'nome', 'peso_potencial'])
            ->map(function (Segmento $s) use ($clientes, $leads, $pessoas) {
                $qtdClientes = (int) ($clientes[$s->codigo] ?? 0);
                $qtdLeads = (int) ($leads[$s->nome] ?? 0);
                $qtdPessoas = (int) ($pessoas[$s->id] ?? 0);

                return [
                    'id' => $s->id,
                    'codigo' => $s->codigo,
                    'nome' => $s->nome,
                    'peso' => (float) $s->peso_potencial,
                    'clientes' => $qtdClientes,
                    'leads' => $qtdLeads,
                    'pessoas' => $qtdPessoas,
                    'podeExcluir' => $s->codigo !== Segmento::CODIGO_SUPERMERCADISTA
                        && $qtdClientes + $qtdLeads + $qtdPessoas === 0,
                ];
            })
            ->all();
    }

    public function criar(string $codigo, string $nome, float $peso): Segmento
    {
        return Segmento::create([
            'codigo' => self::normalizarCodigo($codigo),
            'nome' => self::normalizarNome($nome),
            'peso_potencial' => $peso,
        ]);
    }

    public function atualizar(Segmento $segmento, string $nome, float $peso): void
    {
        $nome = self::normalizarNome($nome);

        DB::transaction(function () use ($segmento, $nome, $peso) {
            if ($nome !== $segmento->nome) {
                // `DB::table` de propósito: renomear não é edição do lead, não mexe no updated_at.
                DB::table('leads')->where('segmento', $segmento->nome)->update(['segmento' => $nome]);
            }

            $segmento->update(['nome' => $nome, 'peso_potencial' => $peso]);
        });
    }

    /** @throws ValidationException quando o segmento está em uso. */
    public function excluir(Segmento $segmento): void
    {
        $uso = collect($this->listar())->firstWhere('id', $segmento->id);
        $contas = DB::table('contas_estrategicas')->where('segmento_id', $segmento->id)->exists();

        if (! ($uso['podeExcluir'] ?? false) || $contas) {
            throw ValidationException::withMessages([
                'segmento' => 'Este segmento está em uso e não pode ser excluído.',
            ]);
        }

        $segmento->delete();
    }

    /** Mesma normalização do import de clientes: o TOTVS manda '101' e '000101'. */
    public static function normalizarCodigo(string $codigo): string
    {
        return (string) (int) trim($codigo);
    }

    public static function normalizarNome(string $nome): string
    {
        return mb_strtoupper(trim($nome));
    }
}
