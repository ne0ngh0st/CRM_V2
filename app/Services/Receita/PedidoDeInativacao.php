<?php

namespace App\Services\Receita;

use App\Models\Cliente;
use App\Models\SolicitacaoInativacao;
use App\Models\User;
use App\Services\Cadastros\EnvioParaCadastro;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Solicitar inativação" (pedido do Tony, 2026-10-01): pede ao Cadastro de clientes que
 * um cliente com CNPJ irregular na Receita seja inativado no TOTVS. Quem pediu vai em
 * cópia.
 *
 * ⚠️ O clique só REGISTRA o pedido; o e-mail sai numa LISTA DIÁRIA (`enviarPendentes()`,
 * às 18h pelo `EnviarInativacoesDoDiaJob`). Até 2026-10-05 era um e-mail por clique, e
 * num dia só foram 43 — a cota do SMTP é de 500 por mês, compartilhada com faturamento
 * e fiscal. Para o Cadastro, uma lista também é melhor que 43 mensagens soltas.
 *
 * ⚠️ O CRM não inativa nada. A Carteira é só leitura (Regra de ouro nº 4): o cliente
 * continua aparecendo até o Cadastro inativá-lo no TOTVS e o import seguinte trazer isso.
 *
 * ⚠️ O pedido é por FILIAL (cliente + loja), não por empresa: cada filial tem o próprio
 * CNPJ e a própria situação. Uma empresa com uma filial baixada e outra ativa só pode ter
 * a baixada inativada.
 *
 * ⚠️ A situação é conferida AQUI, no servidor, e não confiada à tela: o botão só aparece
 * para CNPJ irregular, mas a requisição pode chegar de qualquer lugar, e pedir ao Cadastro
 * que inative um cliente ativo é exatamente o erro que não pode acontecer.
 */
class PedidoDeInativacao
{
    /** Pedido do mesmo cliente dentro desta janela não sai de novo — mostra o anterior. */
    public const DIAS_SEM_REPETIR = 30;

    public function __construct(
        private readonly EnvioParaCadastro $envio,
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {}

    /**
     * @return array{status: 'registrado'|'ja_solicitado'|'nao_irregular', solicitacao?: SolicitacaoInativacao}
     */
    public function solicitar(Cliente $cliente, User $solicitante): array
    {
        $receita = $cliente->cnpj_digitos
            ? DB::table('cnpj_situacoes')->where('cnpj', $cliente->cnpj_digitos)->first()
            : null;

        if (! SituacaoCadastral::irregular($receita?->situacao)) {
            return ['status' => 'nao_irregular'];
        }

        if ($anterior = $this->recente($cliente->id)) {
            return ['status' => 'ja_solicitado', 'solicitacao' => $anterior];
        }

        $solicitacao = SolicitacaoInativacao::create([
            'cliente_id' => $cliente->id,
            'cnpj' => $cliente->cnpj_digitos,
            'situacao_receita' => $receita->situacao,
            'solicitado_por' => $solicitante->id,
        ]);

        return ['status' => 'registrado', 'solicitacao' => $solicitacao];
    }

    /**
     * Manda ao Cadastro, num e-mail só, todos os pedidos ainda não enviados. Devolve
     * quantos foram na lista (0 = nenhum e-mail).
     *
     * ⚠️ Tudo numa transação com lock: o carimbo `enviado_em` só fica gravado se o e-mail
     * entrou na fila. Se o Redis recusar, nada é carimbado e a próxima rodada tenta de
     * novo — perder um pedido aqui é o Cadastro nunca ficar sabendo dele.
     */
    public function enviarPendentes(): int
    {
        return DB::transaction(function () {
            $pendentes = SolicitacaoInativacao::query()
                ->whereNull('enviado_em')
                ->with(['cliente', 'solicitante:id,name,display_name,email'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (SolicitacaoInativacao $s) => $s->cliente !== null)
                ->values();

            if ($pendentes->isEmpty()) {
                return 0;
            }

            $this->envio->enviar($this->emailDaLista($pendentes->all()));

            SolicitacaoInativacao::query()->whereIn('id', $pendentes->pluck('id'))->update(['enviado_em' => now()]);

            return $pendentes->count();
        });
    }

    /**
     * Pedidos recentes por cliente, para a tela trocar o botão por "solicitada em…".
     *
     * @param  iterable<int>  $clienteIds
     * @return array<int, array{em: string, por: ?string}>
     */
    public function recentes(iterable $clienteIds): array
    {
        $ids = array_values(array_unique([...$clienteIds]));

        if ($ids === []) {
            return [];
        }

        return SolicitacaoInativacao::query()
            ->with('solicitante:id,name,display_name')
            ->whereIn('cliente_id', $ids)
            ->where('created_at', '>=', now()->subDays(self::DIAS_SEM_REPETIR))
            ->latest()
            ->get()
            ->unique('cliente_id')
            ->mapWithKeys(fn (SolicitacaoInativacao $s) => [$s->cliente_id => self::paraTela($s)])
            ->all();
    }

    /** @return array{em: string, por: ?string} */
    public static function paraTela(SolicitacaoInativacao $s): array
    {
        $s->loadMissing('solicitante:id,name,display_name');

        return [
            'em' => $s->created_at->format('d/m/Y'),
            'por' => $s->solicitante?->display_name ?: $s->solicitante?->name,
        ];
    }

    private function recente(int $clienteId): ?SolicitacaoInativacao
    {
        return SolicitacaoInativacao::query()
            ->where('cliente_id', $clienteId)
            ->where('created_at', '>=', now()->subDays(self::DIAS_SEM_REPETIR))
            ->latest()
            ->first();
    }

    /**
     * @param  list<SolicitacaoInativacao>  $pedidos
     * @return array{to: string, cc: list<string>, subject: string, body: string}
     */
    private function emailDaLista(array $pedidos): array
    {
        $receitas = DB::table('cnpj_situacoes')
            ->whereIn('cnpj', array_map(fn ($p) => $p->cnpj, $pedidos))
            ->get()
            ->keyBy('cnpj');

        $nomes = $this->nomeVendedor->porCodigo(array_filter(array_map(fn ($p) => $p->cliente->cod_vendedor, $pedidos)));

        $total = count($pedidos);
        $corpo = "Olá!\n\n";
        $corpo .= $total === 1
            ? "Solicitamos a INATIVAÇÃO no TOTVS do cadastro abaixo: o CNPJ está irregular na Receita Federal.\n\n"
            : "Solicitamos a INATIVAÇÃO no TOTVS dos {$total} cadastros abaixo: o CNPJ de cada um está irregular na Receita Federal.\n\n";

        foreach ($pedidos as $n => $pedido) {
            $cliente = $pedido->cliente;
            $receita = $receitas[$pedido->cnpj] ?? null;
            $solicitante = $pedido->solicitante;

            $corpo .= EnvioParaCadastro::secao(($n + 1).". {$cliente->razao_social}", [
                'Código / Loja' => "{$cliente->cod_cliente} / {$cliente->loja}",
                'Nome Fantasia' => $cliente->nome_fantasia,
                'CNPJ' => $cliente->cnpj,
                'Município / UF' => trim(($cliente->municipio ?? '').' / '.($cliente->estado ?? ''), ' /'),
                'Vendedor da carteira' => $cliente->cod_vendedor
                    ? ($nomes[$cliente->cod_vendedor] ?? $cliente->cod_vendedor)." ({$cliente->cod_vendedor})"
                    : null,
                'Última compra' => $cliente->data_ultima_compra?->format('d/m/Y') ?? 'Nunca',
                'Situação na Receita' => $pedido->situacao_receita
                    .($receita?->data_situacao ? ' desde '.Carbon::parse($receita->data_situacao)->format('d/m/Y') : ''),
                'Fonte' => $receita ? self::fonte($receita) : null,
                'Solicitado por' => $solicitante
                    ? ($solicitante->display_name ?: $solicitante->name)." ({$solicitante->email}) em ".$pedido->created_at->format('d/m/Y H:i')
                    : null,
            ]);
        }

        $corpo .= EnvioParaCadastro::assinatura();

        $primeiro = $pedidos[0];
        $assunto = $total === 1
            ? "Inativação de cliente — {$primeiro->situacao_receita} na Receita — {$primeiro->cliente->razao_social} ({$primeiro->cliente->cod_cliente}/{$primeiro->cliente->loja})"
            : "Inativação de clientes — {$total} cadastros com CNPJ irregular na Receita — ".now()->format('d/m/Y');

        return [
            'to' => EnvioParaCadastro::EMAILS['cadastroCliente'],
            'cc' => array_values(array_unique(array_filter(array_map(fn ($p) => $p->solicitante?->email, $pedidos)))),
            'subject' => $assunto,
            'body' => $corpo,
        ];
    }

    private static function fonte(object $receita): string
    {
        return $receita->fonte === SituacaoCadastral::FONTE_BASE
            ? 'Base de dados aberta da Receita Federal'.($receita->referencia ? ' ('.substr($receita->referencia, 5, 2).'/'.substr($receita->referencia, 0, 4).')' : '')
            : 'Consulta ao cartão CNPJ em '.Carbon::parse($receita->atualizado_em)->format('d/m/Y');
    }
}
