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
 * "Solicitar inativação" (pedido do Tony, 2026-10-01): manda um e-mail ao Cadastro de
 * clientes pedindo que um cliente com CNPJ irregular na Receita seja inativado no TOTVS.
 * Quem pediu vai em cópia.
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
     * @return array{status: 'enviado'|'ja_solicitado'|'nao_irregular', solicitacao?: SolicitacaoInativacao, destino?: string}
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

        $dados = $this->email($cliente, $solicitante, $receita);
        $this->envio->enviar($dados);

        return ['status' => 'enviado', 'solicitacao' => $solicitacao, 'destino' => EnvioParaCadastro::destinoDescrito($dados)];
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

    /** @return array{to: string, solicitanteEmail: ?string, subject: string, body: string} */
    private function email(Cliente $cliente, User $solicitante, object $receita): array
    {
        $situacao = $receita->situacao;
        $desde = $receita->data_situacao ? Carbon::parse($receita->data_situacao)->format('d/m/Y') : null;
        $nomeSolicitante = $solicitante->display_name ?: $solicitante->name;

        $fonte = $receita->fonte === SituacaoCadastral::FONTE_BASE
            ? 'Base de dados aberta da Receita Federal'.($receita->referencia ? ' ('.substr($receita->referencia, 5, 2).'/'.substr($receita->referencia, 0, 4).')' : '')
            : 'Consulta ao cartão CNPJ em '.Carbon::parse($receita->atualizado_em)->format('d/m/Y');

        $corpo = "Olá!\n\n";
        $corpo .= "Solicitamos a INATIVAÇÃO do cadastro abaixo no TOTVS: o CNPJ está {$situacao} na Receita Federal.\n\n";
        $corpo .= EnvioParaCadastro::secao('CLIENTE', [
            'Código / Loja' => "{$cliente->cod_cliente} / {$cliente->loja}",
            'Razão Social' => $cliente->razao_social,
            'Nome Fantasia' => $cliente->nome_fantasia,
            'CNPJ' => $cliente->cnpj,
            'Município / UF' => trim(($cliente->municipio ?? '').' / '.($cliente->estado ?? ''), ' /'),
            'Vendedor da carteira' => $cliente->cod_vendedor
                ? $this->nomeVendedor->para($cliente->cod_vendedor)." ({$cliente->cod_vendedor})"
                : null,
            'Última compra' => $cliente->data_ultima_compra?->format('d/m/Y') ?? 'Nunca',
        ]);
        $corpo .= EnvioParaCadastro::secao('SITUAÇÃO NA RECEITA FEDERAL', [
            'Situação' => $situacao,
            'Desde' => $desde,
            'Fonte' => $fonte,
        ]);
        $corpo .= EnvioParaCadastro::secao('SOLICITAÇÃO', [
            'Solicitado por' => "{$nomeSolicitante} ({$solicitante->email})",
            'Data/Hora' => now()->format('d/m/Y H:i'),
        ]);
        $corpo .= EnvioParaCadastro::assinatura();

        return [
            'to' => EnvioParaCadastro::EMAILS['cadastroCliente'],
            'solicitanteEmail' => $solicitante->email,
            'subject' => "Inativação de cliente — {$situacao} na Receita — {$cliente->razao_social} ({$cliente->cod_cliente}/{$cliente->loja})",
            'body' => $corpo,
        ];
    }
}
