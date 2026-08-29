<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        /*
         * `is_active` entra como credencial de busca: o broker repassa cada chave (menos
         * a senha) para o `where` do provider, então usuário desativado simplesmente não
         * é encontrado e nenhum link é gerado. Sem isto, um ex-funcionário desativado
         * ainda receberia e-mail de redefinição — ele não conseguiria entrar (o login
         * checa `is_active`), mas receber o link já é confuso e desnecessário.
         */
        $status = Password::sendResetLink([
            'email' => $request->string('email')->toString(),
            'is_active' => true,
        ]);

        /*
         * ⚠️ Resposta IDÊNTICA em todos os casos — inclusive quando o e-mail não existe.
         *
         * O scaffold do Breeze lançava ValidationException quando não achava a conta, e
         * isso permite enumerar usuários: basta testar endereços e ver qual devolve erro.
         * Num sistema onde o e-mail corporativo segue um padrão previsível
         * (nome.sobrenome@autopel.com), isso entrega a lista de quem tem acesso.
         *
         * O throttle de 60s do broker (config/auth.php) limita a velocidade, mas não
         * resolve o vazamento — só uniformizar a resposta resolve.
         */
        return back()->with('status', 'Se houver uma conta ativa com esse e-mail, enviamos o link de redefinição. Confira também a caixa de spam.');
    }
}
