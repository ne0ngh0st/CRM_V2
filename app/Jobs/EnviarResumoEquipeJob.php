<?php

namespace App\Jobs;

use App\Mail\ResumoEquipeMail;
use App\Models\User;
use App\Services\ResumoEquipe\ResumoEquipeBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Monta e envia o resumo diário de UM destinatário.
 *
 * ⚠️ IDEMPOTENTE POR DIA: a marca `resumo-equipe:enviado:{id}:{data}` é gravada DEPOIS
 * de o SMTP aceitar a mensagem. Retry de um job que falhou antes do envio manda; retry
 * de um que já mandou não manda de novo. Chave com TTL (nunca Cache::forever — ver
 * CLAUDE.md, política volatile-lru do Redis).
 *
 * ⚠️ O CONSOLIDADO É MONTADO UMA VEZ POR RODADA: Paulo e Leandro recebem o mesmo
 * conteúdo, e ele é o mais caro (todas as equipes). O cache de 30 min é por dia+hora,
 * então a rodada de amanhã nunca reaproveita a de hoje.
 */
class EnviarResumoEquipeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly int $userId) {}

    public function handle(ResumoEquipeBuilder $builder): void
    {
        $destinatario = User::query()->with('vendedorPerfil')->find($this->userId);

        if (! $destinatario || ! $destinatario->is_active || $destinatario->resumo_diario === 'nenhum') {
            return;
        }

        $marca = 'resumo-equipe:enviado:'.$destinatario->id.':'.now()->toDateString();
        if (Cache::has($marca)) {
            return;
        }

        $resumo = $destinatario->resumo_diario === 'consolidado'
            ? Cache::remember('resumo-equipe:consolidado:'.now()->format('Y-m-d-H'), now()->addMinutes(30), fn () => $builder->consolidado())
            : $builder->equipe($destinatario);

        if ($resumo === null) {
            // Gestor sem ninguém com `cod_super` apontando para ele. Não manda e-mail vazio,
            // que leria como "a equipe não fez nada" — avisa no log e segue.
            Log::warning('Resumo diário sem equipe; e-mail não enviado.', [
                'user_id' => $destinatario->id,
                'cod_vendedor' => $destinatario->vendedorPerfil?->cod_vendedor,
            ]);

            return;
        }

        self::enviar($resumo, $destinatario);

        Cache::put($marca, true, now()->addHours(20));
    }

    /**
     * Envio respeitando o redirecionamento de teste. Público para o comando reusar a MESMA
     * regra de destino, em vez de uma segunda cópia (Regra de ouro nº 8).
     *
     * @param  array<string, mixed>  $resumo
     */
    public static function enviar(array $resumo, User $destinatario, ?string $forcarPara = null): string
    {
        $redirecionar = $forcarPara ?: config('resumo_equipe.redirecionar_para');
        $destino = $redirecionar ?: $destinatario->email;

        Mail::to($destino)->send(new ResumoEquipeMail(
            $resumo,
            self::primeiroNome($destinatario),
            // Prefixo "[TESTE → ...]" só quando o e-mail foi desviado de quem o receberia.
            $redirecionar && strcasecmp($redirecionar, (string) $destinatario->email) !== 0 ? $destinatario->email : null,
        ));

        return $destino;
    }

    public static function primeiroNome(User $u): string
    {
        $nome = $u->display_name ?: $u->name;

        return mb_convert_case(strtok($nome, ' ') ?: $nome, MB_CASE_TITLE);
    }
}
