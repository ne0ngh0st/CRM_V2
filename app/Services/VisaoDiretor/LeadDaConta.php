<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategica;
use App\Models\Lead;
use App\Models\Observacao;
use App\Models\User;
use App\Services\Notificacao\NotificacaoService;
use App\Services\Totvs\Normalizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Transforma uma conta-alvo com status "Lead" (rede do mercado sem nenhuma loja nossa) em
 * um lead de verdade no funil, já na carteira de um responsável.
 *
 * ⚠️ É ATRIBUIÇÃO NA CRIAÇÃO, não transferência. Transferir lead foi cortado do escopo
 * (2026-08-10); aqui o lead nasce do dono certo e ninguém o move depois. Sem isto, o único
 * caminho era a diretoria cadastrar pelo /cadastros — e como admin/diretor não têm código
 * de vendedor, o lead nascia com `cod_vendedor` nulo e ninguém o via.
 *
 * O lead NÃO muda o status da conta: ele continua "Lead" na Maiores por Segmento até a
 * rede virar cliente no TOTVS e ser vinculada. O status é derivado dos vínculos, e é
 * assim que tem que ser — lead aberto não é loja nossa.
 */
class LeadDaConta
{
    public function __construct(
        private readonly ClientesDaConta $clientesDaConta,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * Quem pode receber o lead: usuário ativo com código de vendedor.
     *
     * ⚠️ Decide pelo CÓDIGO, não pelo perfil: é o código que torna o lead visível
     * (`LeadController::scopeQuery` filtra por `cod_vendedor`). Usuário sem código
     * receberia um lead que nem ele enxerga. De quebra, perfil novo com carteira (ex.:
     * venda interna) entra sozinho, sem mexer aqui.
     *
     * @return list<array{id: int, nome: string, codVendedor: string}>
     */
    public function responsaveis(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereNotNull('cod_vendedor')->where('cod_vendedor', '!=', ''))
            ->with('vendedorPerfil:id,user_id,cod_vendedor')
            ->get(['id', 'name', 'display_name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->display_name ?: $u->name,
                'codVendedor' => $u->vendedorPerfil->cod_vendedor,
            ])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Um lead já ligado a esta conta, se houver — aberto aqui ou trazido pela prospecção
     * (`ContaDoLead`). Lead excluído não conta: a conta volta a oferecer o botão.
     */
    public function leadAberto(ContaEstrategica $conta): ?Lead
    {
        return $conta->leads()->visivel()->oldest('id')->first();
    }

    /**
     * ⚠️ Nunca cria o segundo lead da mesma empresa. Antes de criar, reaproveita:
     *
     * | já existe                                   | o que acontece                         |
     * |---------------------------------------------|----------------------------------------|
     * | lead ligado à conta, SEM dono (prospecção)  | é atribuído ao responsável             |
     * | lead ligado à conta, COM dono               | recusa ("já tem lead aberto com X")    |
     * | lead com o CNPJ informado, sem dono         | é ligado à conta e atribuído           |
     * | lead com o CNPJ informado, com dono         | recusa — não existe transferência      |
     * | cliente com o CNPJ informado                | recusa — trabalhar pela Carteira       |
     *
     * Atribuir lead SEM dono não é transferência (cortada em 2026-08-10): ninguém o via.
     *
     * Devolve o lead; `wasRecentlyCreated` diz se nasceu agora ou foi reaproveitado.
     */
    public function gerar(ContaEstrategica $conta, User $responsavel, User $autor, ?string $recado = null, ?string $cnpj = null): Lead
    {
        $codVendedor = $responsavel->vendedorPerfil?->cod_vendedor;

        if (! $responsavel->is_active || blank($codVendedor)) {
            throw ValidationException::withMessages([
                'responsavel_id' => 'O responsável precisa estar ativo e ter código de vendedor — sem código, ninguém vê o lead.',
            ]);
        }

        /*
         * Só conta SEM loja nossa. Com cliente vinculado a rede já está na Carteira, com
         * vendedor; abrir um lead ao lado criaria dois donos para a mesma empresa.
         */
        if ($this->clientesDaConta->filiais($conta->id)->exists()) {
            throw ValidationException::withMessages([
                'responsavel_id' => 'Esta rede já tem clientes na carteira. Trabalhe-a pela Carteira, não como lead.',
            ]);
        }

        $cnpjFormatado = $this->validarCnpj($cnpj);

        $lead = DB::transaction(function () use ($conta, $responsavel, $autor, $codVendedor, $recado, $cnpjFormatado) {
            // Trava a linha: dois cliques quase simultâneos não podem gerar dois leads.
            $conta = ContaEstrategica::query()->lockForUpdate()->with('segmento:id,nome')->findOrFail($conta->id);

            if ($aberto = $this->leadAberto($conta)) {
                if (filled($aberto->cod_vendedor)) {
                    $dono = $aberto->user?->display_name ?: $aberto->user?->name ?: $aberto->cod_vendedor;

                    throw ValidationException::withMessages([
                        'responsavel_id' => "Esta conta já tem um lead aberto com {$dono}.",
                    ]);
                }

                if ($cnpjFormatado && filled($aberto->cnpj) && $aberto->cnpj !== $cnpjFormatado) {
                    throw ValidationException::withMessages([
                        'cnpj' => "Esta conta já tem um lead sem dono com outro CNPJ ({$aberto->cnpj}). Deixe o campo vazio para atribuí-lo.",
                    ]);
                }

                return $this->atribuir($aberto, $conta, $responsavel, $autor, $codVendedor, $recado, $cnpjFormatado);
            }

            if ($cnpjFormatado && ($existente = $this->leadPorCnpj($cnpjFormatado))) {
                if (filled($existente->cod_vendedor)) {
                    $dono = $existente->user?->display_name ?: $existente->user?->name ?: $existente->cod_vendedor;

                    throw ValidationException::withMessages([
                        'cnpj' => "Este CNPJ já é lead de {$dono} (\"{$existente->nome}\").",
                    ]);
                }

                if ($existente->conta_vinculo === Lead::CONTA_CONFIRMADA && (int) $existente->conta_estrategica_id !== $conta->id) {
                    throw ValidationException::withMessages([
                        'cnpj' => 'Este CNPJ já é lead de outra conta-alvo.',
                    ]);
                }

                return $this->atribuir($existente, $conta, $responsavel, $autor, $codVendedor, $recado, null);
            }

            $lead = Lead::query()->create([
                'origem' => Lead::ORIGEM_MANUAL,
                'user_id' => $responsavel->id,
                'cod_vendedor' => $codVendedor,
                'nome' => $conta->nome,
                'razao_social' => $conta->nome,
                'cnpj' => $cnpjFormatado,
                'estado' => $conta->uf,
                'segmento' => $conta->segmento?->nome,
                'status' => 'ativo',
                'etapa' => Lead::ETAPA_NOVO,
                'etapa_alterada_em' => now(),
            ]);

            $this->registrarContexto($lead, $conta, $autor, $recado);

            $lead->forceFill([
                'conta_estrategica_id' => $conta->id,
                'conta_vinculo' => Lead::CONTA_CONFIRMADA,
            ])->save();

            return $lead;
        });

        $this->avisar($responsavel, $lead, $autor);

        return $lead;
    }

    /** O lead que já existia passa a ser do responsável e fica ligado à conta. */
    private function atribuir(Lead $lead, ContaEstrategica $conta, User $responsavel, User $autor, string $codVendedor, ?string $recado, ?string $cnpj): Lead
    {
        $lead->forceFill(array_filter([
            'user_id' => $responsavel->id,
            'cod_vendedor' => $codVendedor,
            'cnpj' => blank($lead->cnpj) ? $cnpj : null,
            'conta_estrategica_id' => $conta->id,
            'conta_vinculo' => Lead::CONTA_CONFIRMADA,
        ], fn ($v) => $v !== null))->save();

        $this->registrarContexto($lead, $conta, $autor, $recado);

        return $lead;
    }

    /**
     * O contexto vai como observação, não em coluna: é o que o vendedor abre ao pegar o
     * lead, e o lead não tem campo para site nem filiais. O autor é quem abriu o lead (a
     * diretoria), não o responsável.
     */
    private function registrarContexto(Lead $lead, ContaEstrategica $conta, User $autor, ?string $recado): void
    {
        Observacao::query()->create([
            'user_id' => $autor->id,
            'lead_id' => $lead->id,
            // Coluna NOT NULL desde a origem.
            'cnpj' => (string) ($lead->cnpj ?? ''),
            'mensagem' => $this->contexto($conta, $recado),
        ]);
    }

    /** @return ?string o CNPJ mascarado, ou null quando não foi informado */
    private function validarCnpj(?string $cnpj): ?string
    {
        if (blank($cnpj)) {
            return null;
        }

        $digitos = Normalizador::digitosCnpj($cnpj);

        if (! Normalizador::cnpjValido($digitos)) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido — confira os dígitos.']);
        }

        $cliente = DB::table('clientes')->where('cnpj_digitos', $digitos)->first(['razao_social']);

        if ($cliente) {
            throw ValidationException::withMessages([
                'cnpj' => "Este CNPJ já é cliente na carteira ({$cliente->razao_social}). Trabalhe pela Carteira.",
            ]);
        }

        return Normalizador::documento($digitos);
    }

    private function leadPorCnpj(string $cnpjFormatado): ?Lead
    {
        return Lead::query()->visivel()->where('cnpj', $cnpjFormatado)->with('user:id,name,display_name')->oldest('id')->first();
    }

    private function contexto(ContaEstrategica $conta, ?string $recado): string
    {
        $linhas = ["Conta-alvo da diretoria (Maiores por Segmento — {$conta->segmento?->nome})."];

        if ($conta->filiais_mercado) {
            $linhas[] = 'Filiais no mercado: '.number_format($conta->filiais_mercado, 0, ',', '.').'.';
        }
        if (filled($conta->site)) {
            $linhas[] = "Site: {$conta->site}";
        }
        if (filled($recado)) {
            $linhas[] = '';
            $linhas[] = trim($recado);
        }

        return implode("\n", $linhas);
    }

    /**
     * ⚠️ Aviso falhando não desfaz o lead — mesmo tratamento do lead do site. Perder um
     * sino é aceitável; perder o lead não.
     */
    private function avisar(User $responsavel, Lead $lead, User $autor): void
    {
        try {
            $this->notificacoes->notificar(
                destinatario: $responsavel,
                tipo: 'lead_conta_alvo',
                titulo: 'Novo lead da diretoria',
                mensagem: "{$lead->nome} — aberto por ".($autor->display_name ?: $autor->name),
                link: route('leads.index', ['busca' => $lead->nome]),
                referenciaTipo: 'lead',
                referenciaId: $lead->id,
            );
        } catch (Throwable $e) {
            Log::warning('lead-da-conta: nao consegui avisar o responsavel', [
                'lead_id' => $lead->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
