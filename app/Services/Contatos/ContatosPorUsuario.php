<?php

namespace App\Services\Contatos;

use App\Models\Ligacao;

/**
 * Contatos (ligação, WhatsApp, e-mail, presencial) por usuário numa janela, já quebrados
 * por canal.
 *
 * ⚠️ Existe para a Visão do Gestor e o resumo diário por e-mail contarem a MESMA coisa
 * (Regra de ouro nº 8). Até 2026-09-28 a agregação vivia privada no
 * `VisaoGestorController`; o e-mail precisaria de uma segunda cópia, e um filtro novo
 * (ex.: outro status a ignorar) faria o gestor ver um número na tela e outro na caixa.
 *
 * Continua sendo UMA query com um GROUP BY — a quebra por canal entra como coluna
 * (`Ligacao::somarPorCanal`), não como uma consulta por canal.
 */
class ContatosPorUsuario
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{total: int, porCanal: array<string, int>}>
     */
    public function porUsuario(array $ids, string $inicio, string $fim): array
    {
        if ($ids === []) {
            return [];
        }

        return Ligacao::query()
            ->selectRaw('usuario_id, COUNT(*) as total')
            ->tap(fn ($q) => Ligacao::somarPorCanal($q))
            ->whereIn('usuario_id', $ids)
            ->where('status', '!=', 'excluida')
            ->whereBetween('data_ligacao', [$inicio, $fim])
            ->groupBy('usuario_id')
            ->get()
            ->mapWithKeys(fn ($linha) => [(int) $linha->usuario_id => [
                'total' => (int) $linha->total,
                'porCanal' => Ligacao::lerPorCanal($linha),
            ]])
            ->all();
    }
}
