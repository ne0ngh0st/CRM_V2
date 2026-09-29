<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\AgendamentoLigacao;
use App\Models\Lead;
use App\Models\Ligacao;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Leads\ListagemDeLeads;
use App\Services\Marketing\WpLeadCapturaStatus;
use App\Services\Marketing\WpLeadIngestor;
use App\Services\Marketing\WpLeadPayloadParser;
use App\Services\Receita\CartaoCnpjService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    use ExportaPlanilha;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly WpLeadCapturaStatus $wpCaptura,
        private readonly WpLeadIngestor $wpIngestor,
        private readonly ListagemDeLeads $listagem,
    ) {}

    /**
     * `/leads` não é mais página: os leads moram na aba Leads da Carteira desde
     * 2026-09-29 (Clientes · Leads · Funil · Calendário, uma busca só).
     *
     * ⚠️ A rota continua existindo e REDIRECIONA, mantendo a query string. Notificação
     * de lead novo do site grava o link `/leads?...` no banco, há favoritos salvos e a
     * Visão Diretor linka `leads.index` com `busca` — sem o redirect, tudo isso vira 404.
     * `aba=funil|calendario` da página antiga cai na aba de mesmo nome; o resto, em Leads.
     */
    public function index(Request $request): RedirectResponse
    {
        $query = $request->query();
        $query['aba'] = in_array($query['aba'] ?? null, ['funil', 'calendario'], true) ? $query['aba'] : 'leads';

        return redirect()->route('carteira.index', $query);
    }

    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('leads', $request);
    }

    public function enviarTesteWordpress(Request $request): RedirectResponse
    {
        $role = $request->user()->getRoleNames()->first();
        abort_unless(in_array($role, ['admin', 'diretor'], true), 403);

        $this->wpIngestor->ingerirTesteInterno($request->user()->id);

        // Sem isto o resultado só apareceria depois do TTL do cache do status,
        // e o botão pareceria não ter feito nada.
        $this->wpCaptura->esquecer();

        return redirect()->route('leads.index', ['origem' => Lead::ORIGEM_WORDPRESS]);
    }

    /**
     * O que o cliente preencheu no site, em forma de ficha legível.
     *
     * ⚠️ Quem abre isto é VENDEDOR, não técnico. Até 2026-09-03 a tela despejava
     * `JSON.stringify(payload, null, 2)` num <pre> — quem precisava do telefone do
     * cliente tinha que garimpar chave crua de plugin do WordPress. Agora os campos
     * comerciais vêm prontos em `campos`, e o JSON continua disponível só para admin,
     * em `tecnico`, porque é o que permite diagnosticar um formato de payload novo.
     *
     * ⚠️ Os rótulos comerciais saem de `WpLeadPayloadParser::extrairCampos()`, o MESMO
     * dicionário de aliases que a promoção do lead usa. Não duplicar esse mapa no front:
     * se ele divergir, a ficha mostra um valor e o lead guarda outro. Regra de ouro nº 8.
     */
    public function capturaWordpress(Request $request, Lead $lead, WpLeadPayloadParser $parser): JsonResponse
    {
        $this->autorizarLead($request, $lead);
        abort_unless($lead->origem === Lead::ORIGEM_WORDPRESS, 404);

        $staging = $lead->stagingWordpress()->with('formulario:id,nome')->first();
        abort_unless($staging, 404);

        $envelope = json_decode($staging->payload_json, true);

        return response()->json([
            'fonte' => $staging->fonte,
            'recebidoEm' => $staging->recebido_em?->format('d/m/Y H:i:s'),
            'formulario' => $staging->formulario?->nome,
            'campos' => $parser->extrairCampos($this->camposCrusDoEnvelope($envelope)),
            // Só admin. Ver o docblock acima.
            'tecnico' => $request->user()->hasRole('admin') ? [
                'remoteAddr' => $staging->remote_addr,
                'userAgent' => $staging->user_agent,
                'tentativas' => $staging->tentativas,
                'erro' => $staging->erro,
                'payloadHash' => $staging->payload_hash,
                'payload' => $envelope ?? $staging->payload_json,
            ] : null,
        ]);
    }

    /**
     * O envelope tem TRÊS formas, e a ficha precisa aguentar as três:
     * webhook e teste interno guardam os campos em `parsed`; o import de CSV guarda em
     * `colunas`. E `json_decode` devolve null quando o payload gravado não é JSON válido
     * — nesse caso não há campo comercial nenhum a extrair, e a ficha fica vazia em vez
     * de estourar.
     *
     * @return array<string, mixed>
     */
    private function camposCrusDoEnvelope(mixed $envelope): array
    {
        if (! is_array($envelope)) {
            return [];
        }

        foreach (['parsed', 'colunas'] as $chave) {
            if (isset($envelope[$chave]) && is_array($envelope[$chave])) {
                return $envelope[$chave];
            }
        }

        return [];
    }

    /** "Carregar mais" de UMA coluna, sem remontar o quadro inteiro. */
    public function maisDoFunil(Request $request): JsonResponse
    {
        $etapa = (string) $request->string('etapa');
        abort_unless(in_array($etapa, Lead::ETAPAS_ABERTAS, true), 422);

        return response()->json([
            'etapa' => $etapa,
            'cards' => $this->listagem->cardsDaColuna($request, $etapa, $request->integer('depois') ?: null),
        ]);
    }

    /**
     * ÚNICO ponto de escrita da etapa vindo da tela. Arrastar o card e clicar no "→"
     * caem os dois aqui — mesma validação, mesma autorização (Regra de ouro nº 8).
     *
     * ⚠️ A etapa chega da requisição e vira valor de um ENUM do MySQL. Sem o `Rule::in`,
     * um valor arbitrário grava STRING VAZIA em silêncio fora do modo estrito, e a
     * contagem por coluna passa a mentir sem erro nenhum aparecer. Mesmo risco da
     * whitelist de ordenação da Carteira: isto é segurança, não organização.
     */
    public function moverEtapa(Request $request, Lead $lead): JsonResponse
    {
        $this->autorizarLead($request, $lead);

        $data = $request->validate([
            'etapa' => ['required', Rule::in(Lead::ETAPAS)],
            // Perder sem dizer por quê é o que torna o funil inútil como diagnóstico.
            'motivo_perda' => [Rule::requiredIf($request->input('etapa') === Lead::ETAPA_PERDIDO), 'nullable', 'string', 'max:255'],
        ]);

        $lead->moverParaEtapa($data['etapa'], $data['motivo_perda'] ?? null);

        /*
         * ⚠️ JSON, não `back()`. Mover um card não é navegação: um redirect faria o Inertia
         * refazer a visita, e `funil` é prop OPCIONAL — ela não voltaria, o `v-if` da
         * página derrubaria o quadro e o vendedor perderia a tela a cada movimento.
         * (Confirmado no navegador antes de mudar.) O quadro já mantém o estado local e
         * desfaz sozinho quando esta resposta falha.
         */
        return response()->json([
            'etapa' => $lead->etapa,
            'proximaEtapa' => $lead->proximaEtapa(),
            'paradoDesde' => $lead->etapa_alterada_em?->toIso8601String(),
        ]);
    }

    /**
     * A query da lista (filtros + ordenação). Existe só porque a exportação
     * (`CatalogoDeExportacoes`) a pede aqui; quem a monta é `ListagemDeLeads`, o mesmo
     * que alimenta a aba Leads — é isso que mantém o Excel igual à tela.
     */
    public function listaQuery(Request $request): Builder
    {
        return $this->listagem->lista($request);
    }

    /**
     * Registra um contato com o lead. Mesmo contrato do `CarteiraController` —
     * o canal vem do front e é validado contra `Ligacao::TIPOS_CONTATO`.
     */
    public function registrarLigacao(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $tipo = $request->validate([
            'tipo' => ['nullable', Rule::in(Ligacao::TIPOS_CONTATO)],
        ])['tipo'] ?? 'telefonica';

        Ligacao::create([
            'usuario_id' => $request->user()->id,
            'lead_id' => $lead->id,
            'cliente_nome' => $lead->razao_social ?: $lead->nome,
            'tipo_contato' => $tipo,
            'status' => 'finalizada',
            'data_ligacao' => now(),
        ]);

        return back();
    }

    public function registrarAgendamento(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $data = $request->validate([
            'data_agendamento' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        AgendamentoLigacao::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'data_agendamento' => $data['data_agendamento'],
            'observacao' => $data['observacao'] ?? null,
            'status' => 'agendado',
        ]);

        return back();
    }

    public function atualizarAgendamento(Request $request, AgendamentoLigacao $agendamento): RedirectResponse
    {
        $agendamento->load('lead');
        abort_unless($agendamento->lead_id, 404);

        $scope = $this->scopeResolver->resolve($request->user(), null, null);
        if ($scope['codVendedores'] !== null
            && ! in_array($agendamento->lead?->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:agendado,realizado,cancelado'],
        ]);

        $agendamento->update(['status' => $data['status']]);

        return back();
    }

    public function excluir(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $lead->update(['status' => 'excluido']);

        return back();
    }

    /**
     * "Verificar cartão CNPJ" do lead, comparado com o que foi digitado nele (pelo
     * vendedor ou pelo formulário do site). Mesmo escopo das outras ações da linha.
     */
    public function cartaoCnpj(Request $request, Lead $lead, CartaoCnpjService $servico): JsonResponse
    {
        $this->autorizarLead($request, $lead);

        return $servico->resposta($lead->cnpj, $request->boolean('atualizar'), CartaoCnpjService::cadastroDoLead($lead));
    }

    private function autorizarLead(Request $request, Lead $lead): void
    {
        $scope = $this->scopeResolver->resolve($request->user(), null, null);
        if ($scope['codVendedores'] !== null && ! in_array($lead->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }
    }
}
