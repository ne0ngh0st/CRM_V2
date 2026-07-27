<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user()->loadMissing('vendedorPerfil');

        return Inertia::render('Profile/Edit', [
            'perfil' => [
                'nome' => $user->display_name ?: $user->name,
                'email' => $user->email,
                'telefone' => $user->telefone,
                'role' => $user->getRoleNames()->first(),
                'cod_vendedor' => $user->vendedorPerfil?->cod_vendedor,
                'foto_url' => $user->foto_url,
            ],
            'status' => session('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('status', 'dados-atualizados');
    }

    public function updateFoto(Request $request): RedirectResponse
    {
        $request->validate([
            'foto_perfil' => ['required', 'image', 'mimes:jpeg,jpg,png,gif', 'max:5120'],
        ], [
            'foto_perfil.required' => 'Selecione uma foto.',
            'foto_perfil.image' => 'O arquivo deve ser uma imagem.',
            'foto_perfil.mimes' => 'Formatos aceitos: JPG, PNG ou GIF.',
            'foto_perfil.max' => 'A foto deve ter no máximo 5MB.',
        ]);

        $user = $request->user();
        $this->apagarFotoAntiga($user->foto_perfil);

        $ext = $request->file('foto_perfil')->getClientOriginalExtension()
            ?: $request->file('foto_perfil')->extension();
        $path = $request->file('foto_perfil')->storeAs(
            'perfis',
            'perfil_'.$user->id.'_'.time().'.'.strtolower($ext),
            'public',
        );

        $user->update(['foto_perfil' => $path]);

        return back()->with('status', 'foto-atualizada');
    }

    public function destroyFoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->apagarFotoAntiga($user->foto_perfil);
        $user->update(['foto_perfil' => null]);

        return back()->with('status', 'foto-removida');
    }

    private function apagarFotoAntiga(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'perfis/')) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
