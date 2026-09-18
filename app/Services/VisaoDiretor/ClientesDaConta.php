<?php

namespace App\Services\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A ÚNICA definição de "os clientes desta conta estratégica".
 *
 * 🥇 Regra de ouro nº 8 em estado puro: a página Maiores por Segmento CONTA por aqui
 * ("N clientes") e a Carteira FILTRA por aqui (`?conta_alvo=`). Se as duas definissem o
 * conjunto cada uma do seu jeito, o número clicado e a lista aberta divergiriam no
 * primeiro vínculo novo — e a invariante "os números da tela têm que bater" existe
 * exatamente contra isso. Há teste comparando os dois caminhos.
 *
 * Um cliente (filial) pertence à conta se o GRUPO dele (`clientes.cod_grupo`) ou o CÓDIGO
 * dele (`clientes.cod_cliente`) estiver entre os vínculos. Nunca por `raiz_cnpj` (Regra de
 * ouro nº 3) nem por nome — nome é só a SUGESTÃO da carga inicial.
 */
class ClientesDaConta
{
    /**
     * Restringe uma consulta de `clientes` às filiais da conta. É o que a Carteira usa.
     *
     * Conta sem vínculo (ou inexistente) não casa nada — nunca "sem restrição": um filtro
     * que se desliga sozinho abriria a carteira inteira sob o nome de uma conta.
     */
    public function aplicar(Builder|QueryBuilder $query, int $contaId): void
    {
        $vinculos = ContaEstrategicaVinculo::query()
            ->where('conta_id', $contaId)
            ->get(['tipo', 'codigo']);

        $grupos = $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_GRUPO)->pluck('codigo')->all();
        $codigos = $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)->pluck('codigo')->all();

        if ($grupos === [] && $codigos === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($grupos, $codigos) {
            if ($grupos !== []) {
                $q->orWhereIn('clientes.cod_grupo', $grupos);
            }

            if ($codigos !== []) {
                $q->orWhereIn('clientes.cod_cliente', $codigos);
            }
        });
    }

    /** As filiais de UMA conta, prontas para listar. */
    public function filiais(int $contaId): Builder
    {
        $query = Cliente::query();
        $this->aplicar($query, $contaId);

        return $query;
    }

    /**
     * Pares (conta_id, cliente_id) de TODAS as contas de uma vez — a base da agregação da
     * página, que precisa das ~370 contas numa consulta só em vez de 370.
     *
     * ⚠️ `UNION` (distinto) e não `UNION ALL`: uma filial pode casar pelos dois caminhos
     * na mesma conta (o grupo dela E o código dela estão vinculados). Com `UNION ALL` ela
     * contaria duas vezes em "Clientes", e esse número deixaria de bater com a
     * Carteira — que filtra com `OR` e a conta uma vez só.
     *
     * Traz junto as colunas que a agregação usa, para ela não precisar voltar a `clientes`.
     */
    public function paresDeTodasAsContas(): QueryBuilder
    {
        $colunas = [
            'v.conta_id',
            'c.id as cliente_id',
            'c.cod_cliente',
            'c.cod_vendedor',
            'c.data_ultima_compra',
        ];

        $porGrupo = DB::table('conta_estrategica_vinculos as v')
            ->join('clientes as c', 'c.cod_grupo', '=', 'v.codigo')
            ->where('v.tipo', ContaEstrategicaVinculo::TIPO_GRUPO)
            ->select($colunas);

        $porCodigo = DB::table('conta_estrategica_vinculos as v')
            ->join('clientes as c', 'c.cod_cliente', '=', 'v.codigo')
            ->where('v.tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)
            ->select($colunas);

        return $porGrupo->union($porCodigo);
    }

    /**
     * Substitui os vínculos da conta e sobe a versão dela.
     *
     * ⚠️ Toda escrita de vínculo passa por aqui, porque é aqui que `vinculos_versao` sobe —
     * e é essa versão que entra na chave do total cacheado da Carteira. Vínculo gravado por
     * outro caminho deixaria a Carteira mostrando o total antigo por até 10 minutos.
     *
     * @param  list<array{tipo: string, codigo: string, origem?: string}>  $vinculos
     */
    public function sincronizarVinculos(ContaEstrategica $conta, array $vinculos): void
    {
        $normalizados = collect($vinculos)
            ->map(fn (array $v) => [
                'tipo' => $v['tipo'],
                'codigo' => trim((string) $v['codigo']),
                'origem' => $v['origem'] ?? ContaEstrategicaVinculo::ORIGEM_MANUAL,
            ])
            ->filter(fn (array $v) => $v['codigo'] !== '')
            ->unique(fn (array $v) => $v['tipo'].':'.$v['codigo'])
            ->values();

        $proibido = $normalizados->first(fn (array $v) => $v['tipo'] === ContaEstrategicaVinculo::TIPO_GRUPO
            && in_array($v['codigo'], ContaEstrategicaVinculo::GRUPOS_PROIBIDOS, true));

        if ($proibido) {
            throw ValidationException::withMessages([
                'vinculos' => "O grupo {$proibido['codigo']} (CLIENTES DIVERSOS) não pode ser vinculado: ele junta clientes sem grupo real e ligaria ~30% da base a esta conta.",
            ]);
        }

        DB::transaction(function () use ($conta, $normalizados) {
            $conta->vinculos()->delete();

            $agora = now();
            $conta->vinculos()->insert($normalizados->map(fn (array $v) => $v + [
                'conta_id' => $conta->id,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])->all());

            ContaEstrategica::query()->whereKey($conta->id)->increment('vinculos_versao');
        });
    }

    /**
     * O pedaço da chave de cache da Carteira que identifica este recorte: id + versão dos
     * vínculos. `null` se a conta não existe (o filtro então é ignorado).
     */
    public function assinatura(int $contaId): ?string
    {
        $versao = ContaEstrategica::query()->whereKey($contaId)->value('vinculos_versao');

        return $versao === null ? null : "{$contaId}:{$versao}";
    }
}
