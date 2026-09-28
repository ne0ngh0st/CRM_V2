<?php

namespace App\Console\Commands;

use App\Jobs\EnviarResumoEquipeJob;
use App\Jobs\EnviarResumosEquipeJob;
use App\Mail\ResumoEquipeMail;
use App\Models\User;
use App\Services\ResumoEquipe\ResumoEquipeBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Resumo diário da equipe, à mão — para conferir antes de ligar o agendamento.
 *
 *   --previa          grava o HTML em storage/app/relatorios/ e NÃO envia nada
 *   --para=EMAIL      envia SÓ para este endereço (funciona com o agendamento desligado)
 *   --consolidado     o resumo de todas as equipes (o que Paulo e Leandro recebem)
 *   --usuario=ID|EMAIL  o resumo que esta pessoa recebe (ou a equipe dela)
 *
 * Sem --previa nem --para: roda o disparo normal agora (respeita o interruptor).
 *
 * ⚠️ Nunca testar mandando para o gestor de verdade — use --para com o seu e-mail ou
 * com o de quem vai validar.
 */
class EnviarResumoEquipe extends Command
{
    protected $signature = 'resumo-equipe:enviar
        {--usuario= : ID ou e-mail do destinatário/gestor}
        {--consolidado : Resumo de todas as equipes}
        {--para= : Envia só para este endereço}
        {--previa : Só grava o HTML, sem enviar}';

    protected $description = 'Gera/envia o resumo diário da equipe por e-mail';

    public function handle(ResumoEquipeBuilder $builder): int
    {
        if (! $this->option('previa') && ! $this->option('para')) {
            if (! config('resumo_equipe.habilitado')) {
                $this->warn('Envio agendado desligado (RESUMO_EQUIPE_HABILITADO=false). Use --previa ou --para.');

                return self::FAILURE;
            }
            EnviarResumosEquipeJob::dispatchSync();
            $this->info('Disparo enfileirado para todos os destinatários marcados.');

            return self::SUCCESS;
        }

        $usuario = $this->usuario();
        if ($this->option('usuario') && ! $usuario) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $inicio = microtime(true);
        $resumo = match (true) {
            $this->option('consolidado') => $builder->consolidado(),
            $usuario !== null && $usuario->resumo_diario === 'consolidado' => $builder->consolidado(),
            $usuario !== null => $builder->equipe($usuario),
            default => null,
        };
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($resumo === null) {
            $this->error($usuario
                ? 'Esta pessoa não tem equipe (ninguém com cod_super apontando para o código dela).'
                : 'Informe --usuario ou --consolidado.');

            return self::FAILURE;
        }

        $this->line(sprintf('%s · %d seção(ões) · %d pessoas · montado em %d ms',
            $resumo['titulo'], count($resumo['secoes']), $resumo['vendedores'], $ms));

        if ($this->option('previa')) {
            $nome = $usuario ? EnviarResumoEquipeJob::primeiroNome($usuario) : 'Gestor';
            $html = view('emails.resumo-equipe', ResumoEquipeMail::dadosDaView($resumo, $nome))->render();
            $arquivo = storage_path('app/relatorios/resumo-equipe-'.str($resumo['titulo'])->slug().'-'.now()->format('Ymd-His').'.html');
            File::ensureDirectoryExists(dirname($arquivo));
            File::put($arquivo, $html);
            $this->info("Prévia: {$arquivo}");
        }

        if ($para = $this->option('para')) {
            // O destinatário "de fachada" só dá o nome da saudação; o endereço é o --para.
            $destinatario = $usuario ?? new User(['name' => 'Gestor', 'email' => $para]);
            EnviarResumoEquipeJob::enviar($resumo, $destinatario, $para);
            $this->info("Enviado para {$para}.");
        }

        return self::SUCCESS;
    }

    private function usuario(): ?User
    {
        $valor = $this->option('usuario');
        if (! $valor) {
            return null;
        }

        return User::query()
            ->with('vendedorPerfil')
            ->where(is_numeric($valor) ? 'id' : 'email', $valor)
            ->first();
    }
}
