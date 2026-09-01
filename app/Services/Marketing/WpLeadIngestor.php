<?php

namespace App\Services\Marketing;

use App\Models\Lead;
use App\Models\MarketingWpFormulario;
use App\Models\MarketingWpLeadRaw;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Captura do site em DOIS passos deliberadamente desacoplados:
 *
 *   1. staging  — o envelope cru, gravado e COMMITADO sozinho;
 *   2. promoção — o lead comercial que o vendedor trabalha.
 *
 * ⚠️ Os dois NÃO compartilham transação, e isso é o ponto da classe inteira.
 * O WordPress dispara o webhook uma vez e nunca reenvia: se a promoção
 * falhasse dentro da mesma transação da staging, o rollback levaria o
 * envelope junto e o lead do cliente sumiria sem deixar rastro. Com eles
 * separados, o pior caso vira "chegou, ainda não virou lead" — e
 * PromoverCapturasWpPendentesJob fecha isso sozinho depois.
 *
 * Corolário: nada aqui pode lançar exceção depois que a staging foi gravada.
 */
class WpLeadIngestor
{
    public const FONTE_WEBHOOK = 'wordpress_webhook';

    public const FONTE_CSV = 'historico_csv';

    public const FONTE_TESTE = 'teste_interno';

    public const TZ = 'America/Sao_Paulo';

    /** Retry do WordPress chega em segundos; 10 min cobre com folga sem colar envios distintos. */
    private const JANELA_IDEMPOTENCIA_MIN = 10;

    /** Mesmo e-mail no mesmo dia reaproveita o lead em vez de encher a carteira do vendedor. */
    private const JANELA_DEDUPE_LEAD_H = 24;

    public function __construct(
        private readonly WpLeadPayloadParser $parser,
        private readonly WpLeadFormularioResolver $formularios,
    ) {
    }

    /**
     * @return array{staging: MarketingWpLeadRaw, duplicada: bool}
     */
    public function ingerirDoWebhook(Request $request): array
    {
        $raw = $request->getContent();
        $parsed = $this->parser->parsearCorpo($raw, $request->post());
        $agora = Carbon::now(self::TZ);

        return $this->capturar(
            envelope: [
                'fonte' => self::FONTE_WEBHOOK,
                'recebido_em' => $agora->toIso8601String(),
                'content_type' => $request->header('Content-Type') ?? '',
                'raw_input' => $raw,
                'parsed' => $parsed,
            ],
            fonte: self::FONTE_WEBHOOK,
            recebidoEm: $agora,
            remoteAddr: $request->ip(),
            userAgent: $this->truncarUa($request->userAgent()),
            hashDe: self::FONTE_WEBHOOK.'|'.$raw,
        );
    }

    /**
     * @param  array<string, mixed>  $colunas
     * @return array{staging: MarketingWpLeadRaw, duplicada: bool}
     */
    public function ingerirDoCsv(
        array $colunas,
        string $arquivo,
        ?string $rotulo,
        int $linhaCsv,
        string $userAgent,
    ): array {
        $agora = Carbon::now(self::TZ);
        $recebidoEm = $this->parser->dataDoCsv($colunas) ?? $agora;

        return $this->capturar(
            envelope: [
                'fonte' => self::FONTE_CSV,
                'arquivo' => $arquivo,
                'rotulo' => $rotulo,
                'linha_csv' => $linhaCsv,
                'importado_em' => $agora->toIso8601String(),
                'colunas' => $colunas,
            ],
            fonte: self::FONTE_CSV,
            recebidoEm: $recebidoEm,
            remoteAddr: null,
            userAgent: $userAgent,
            hashDe: self::FONTE_CSV.'|'.$arquivo.'|'.$linhaCsv.'|'.json_encode($colunas),
            janelaIdempotenciaMin: null,
        );
    }

    /**
     * Prova o pipeline sem depender do WordPress. O lead de teste é
     * descartável: ExpurgarCapturasWpJob apaga fonte=teste_interno depois de
     * 24h, então clicar o botão não suja a carteira do vendedor para sempre.
     *
     * @return array{staging: MarketingWpLeadRaw, duplicada: bool}
     */
    public function ingerirTesteInterno(int $userId): array
    {
        $agora = Carbon::now(self::TZ);
        $parsed = [
            'nome' => 'Lead de teste (CRM)',
            'email' => 'teste.wordpress@autopel.com',
            'formulario' => MarketingWpFormulario::IDENTIFICADOR_PADRAO,
        ];

        return $this->capturar(
            envelope: [
                'fonte' => self::FONTE_TESTE,
                'recebido_em' => $agora->toIso8601String(),
                'disparado_por_user_id' => $userId,
                'parsed' => $parsed,
            ],
            fonte: self::FONTE_TESTE,
            recebidoEm: $agora,
            remoteAddr: null,
            userAgent: 'CRM teste interno',
            hashDe: self::FONTE_TESTE.'|'.$userId.'|'.$agora->toIso8601String(),
            janelaIdempotenciaMin: null,
        );
    }

    /**
     * Passo 1: grava o envelope e commita. Depois tenta promover, sem deixar
     * que uma falha na promoção alcance quem chamou.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{staging: MarketingWpLeadRaw, duplicada: bool}
     */
    private function capturar(
        array $envelope,
        string $fonte,
        Carbon $recebidoEm,
        ?string $remoteAddr,
        ?string $userAgent,
        string $hashDe,
        ?int $janelaIdempotenciaMin = self::JANELA_IDEMPOTENCIA_MIN,
    ): array {
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('json_encode_failed');
        }

        $hash = hash('sha256', $hashDe);

        if ($janelaIdempotenciaMin !== null) {
            $anterior = $this->capturaRecenteComMesmoHash($hash, $recebidoEm, $janelaIdempotenciaMin);
            if ($anterior !== null) {
                return ['staging' => $anterior, 'duplicada' => true];
            }
        }

        $staging = MarketingWpLeadRaw::query()->create([
            'recebido_em' => $recebidoEm,
            'payload_json' => $json,
            'payload_hash' => $hash,
            'remote_addr' => $remoteAddr,
            'user_agent' => $userAgent,
            'fonte' => $fonte,
            'formulario_id' => null,
            'tentativas' => 0,
        ]);

        // Daqui para baixo nada pode escapar: o envelope já está salvo.
        $this->promover($staging);

        return ['staging' => $staging, 'duplicada' => false];
    }

    /**
     * Passo 2: envelope → lead comercial. Idempotente e seguro para repetir —
     * é exatamente isto que PromoverCapturasWpPendentesJob chama de novo.
     */
    public function promover(MarketingWpLeadRaw $staging): ?Lead
    {
        if ($staging->lead_id !== null) {
            return $staging->lead;
        }

        try {
            $envelope = json_decode((string) $staging->payload_json, true);
            if (! is_array($envelope)) {
                return $this->registrarFalha($staging, 'envelope_ilegivel', definitiva: true);
            }

            [$campos, $rotuloCsv] = $this->camposDoEnvelope($envelope);
            $extraidos = $this->parser->extrairCampos($campos);

            // Submissão vazia (bot batendo no form, campo obrigatório burlado).
            // Fica registrada, mas não vira lead nem fica em retry eterno.
            if ($this->tudoVazio($extraidos)) {
                return $this->registrarFalha($staging, 'payload_sem_campos_comerciais', definitiva: true);
            }

            // Resolvido na promoção, não na captura: se a linha `*` de
            // marketing_wp_formularios for cadastrada depois, o retry acerta o dono.
            $formulario = $this->formularios->resolver($campos, $rotuloCsv);

            $lead = $this->leadExistentePara($extraidos['email'])
                ?? $this->criarLeadComercial($extraidos, $formulario?->cod_vendedor);

            $staging->forceFill([
                'lead_id' => $lead->id,
                'formulario_id' => $formulario?->id,
                'erro' => null,
            ])->save();

            return $lead;
        } catch (Throwable $e) {
            return $this->registrarFalha(
                $staging,
                Str::limit($e->getMessage(), 480, ''),
                definitiva: false,
                excecao: $e,
            );
        }
    }

    /**
     * Contabiliza a falha na própria linha em vez de propagar. `definitiva`
     * queima as tentativas de uma vez: retentar payload vazio nunca vai dar certo.
     */
    private function registrarFalha(
        MarketingWpLeadRaw $staging,
        string $erro,
        bool $definitiva,
        ?Throwable $excecao = null,
    ): ?Lead {
        try {
            $staging->forceFill([
                'erro' => $erro,
                'tentativas' => $definitiva
                    ? MarketingWpLeadRaw::MAX_TENTATIVAS
                    : (int) $staging->tentativas + 1,
            ])->save();
        } catch (Throwable $e) {
            Log::error('wp-lead: falha ao registrar erro na staging', [
                'staging_id' => $staging->id,
                'erro' => $e->getMessage(),
            ]);
        }

        if ($excecao !== null) {
            report($excecao);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function camposDoEnvelope(array $envelope): array
    {
        $campos = $envelope['parsed'] ?? $envelope['colunas'] ?? [];
        $rotulo = isset($envelope['rotulo']) && is_string($envelope['rotulo']) ? $envelope['rotulo'] : null;

        return [is_array($campos) ? $campos : [], $rotulo];
    }

    /**
     * @param  array{nome: ?string, email: ?string, telefone: ?string, empresa: ?string}  $extraidos
     */
    private function tudoVazio(array $extraidos): bool
    {
        foreach ($extraidos as $valor) {
            if ($valor !== null && trim($valor) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Mesmo e-mail, mesma origem, últimas 24h: é a mesma pessoa insistindo, não um lead novo. */
    private function leadExistentePara(?string $email): ?Lead
    {
        $email = $email !== null ? trim($email) : '';
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return Lead::query()
            ->where('origem', Lead::ORIGEM_WORDPRESS)
            ->where('email', $email)
            ->where('created_at', '>=', now()->subHours(self::JANELA_DEDUPE_LEAD_H))
            ->latest('id')
            ->first();
    }

    private function capturaRecenteComMesmoHash(string $hash, Carbon $recebidoEm, int $minutos): ?MarketingWpLeadRaw
    {
        $limite = $recebidoEm->copy()->subMinutes($minutos)->format('Y-m-d H:i:s');

        return MarketingWpLeadRaw::query()
            ->where('payload_hash', $hash)
            ->where('recebido_em', '>=', $limite)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array{nome: ?string, email: ?string, telefone: ?string, empresa: ?string}  $extraidos
     */
    private function criarLeadComercial(array $extraidos, ?string $codVendedor): Lead
    {
        $nome = $extraidos['nome'] ?: $extraidos['empresa'] ?: $extraidos['email'] ?: 'Lead do site';
        $razao = $extraidos['empresa'] ?: $nome;
        $email = $extraidos['email'];

        // E-mail inválido não invalida o lead (o telefone pode ser o contato bom),
        // mas não pode entrar na coluna e quebrar o "Enviar e-mail" depois.
        if ($email !== null && ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        return Lead::query()->create([
            'origem' => Lead::ORIGEM_WORDPRESS,
            'user_id' => null,
            'cod_vendedor' => $codVendedor !== null && $codVendedor !== '' ? $codVendedor : null,
            'nome' => Str::limit($nome, 255, ''),
            'razao_social' => Str::limit($razao, 255, ''),
            'nome_fantasia' => $extraidos['empresa'] ? Str::limit($extraidos['empresa'], 255, '') : null,
            'email' => $email ? Str::limit($email, 255, '') : null,
            'telefone' => $extraidos['telefone'] ? Str::limit($extraidos['telefone'], 30, '') : null,
            'status' => 'ativo',
        ]);
    }

    private function truncarUa(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        return Str::limit($ua, 512, '');
    }
}
