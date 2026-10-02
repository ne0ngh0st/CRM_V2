<?php

namespace App\Http\Controllers;

use App\Models\EmailEnviado;
use App\Services\Email\CotaDeEmails;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/emails` — o que saiu pelo SMTP. Admin-only, checado aqui como em
 * `AtualizacaoDadosController`. Só leitura; quem escreve é `RegistroDeEmails`.
 */
class EmailEnviadoController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $status = in_array($request->query('status'), ['enviado', 'falhou'], true) ? $request->query('status') : '';
        $busca = trim((string) $request->query('busca', ''));

        $emails = EmailEnviado::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('assunto', 'like', "%{$busca}%")
                ->orWhere('para', 'like', "%{$busca}%")
                ->orWhere('cc', 'like', "%{$busca}%")))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (EmailEnviado $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'assunto' => $e->assunto,
                'remetente' => $e->remetente,
                'para' => $e->para,
                'cc' => $e->cc,
                'bcc' => $e->bcc,
                'anexos' => $e->anexos ?? [],
                'origem' => $e->origem,
                'erro' => $e->erro,
                'quando' => $e->created_at?->format('d/m/Y H:i:s'),
            ]);

        return Inertia::render('Emails/Index', [
            'emails' => $emails,
            'filtros' => ['status' => $status, 'busca' => $busca],
            'diasRetencao' => EmailEnviado::DIAS_RETENCAO,
            'cota' => CotaDeEmails::resumo(),
            // Os dois interruptores de teste: com valor, NADA chega a quem deveria.
            'redirecionamentos' => array_filter([
                'Cadastros' => config('cadastros.redirecionar_emails_para'),
                'Resumo diário' => config('resumo_equipe.redirecionar_para'),
            ]),
        ]);
    }
}
