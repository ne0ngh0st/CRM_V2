<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        // min:6 igual ao legado (perfil_novo.php) — Password::defaults() do Laravel é 8.
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'current_password.required' => 'Informe a senha atual.',
            'current_password.current_password' => 'A senha atual está incorreta.',
            'password.required' => 'Informe a nova senha.',
            'password.min' => 'A nova senha deve ter no mínimo 6 caracteres.',
            'password.confirmed' => 'A confirmação da nova senha não confere.',
        ]);

        // Cast `hashed` no User já aplica o hash — não usar Hash::make aqui.
        $request->user()->update([
            'password' => $validated['password'],
        ]);

        return back()->with('status', 'senha-atualizada');
    }
}
