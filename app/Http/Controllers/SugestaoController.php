<?php

namespace App\Http\Controllers;

use App\Models\Sugestao;
use Illuminate\Http\Request;

class SugestaoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isGestor = $user->hasAnyRole(['admin', 'diretor']);

        $query = Sugestao::query()->with('user:id,name,display_name')->latest();

        if (! $isGestor) {
            $query->where('visivel', true);
        }

        return $query->get()->map(fn (Sugestao $s) => [
            'id' => $s->id,
            'autor' => $s->user->display_name ?: $s->user->name,
            'categoria' => $s->categoria,
            'mensagem' => $s->mensagem,
            'status' => $s->status,
            'respostaAdmin' => $s->resposta_admin,
            'visivel' => $s->visivel,
            'criadoEm' => $s->created_at->toIso8601String(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'categoria' => ['nullable', 'string', 'max:50'],
            'mensagem' => ['required', 'string', 'max:2000'],
        ]);

        $sugestao = Sugestao::create([
            'user_id' => $request->user()->id,
            'categoria' => $data['categoria'] ?? null,
            'mensagem' => $data['mensagem'],
        ]);

        return response()->json($sugestao, 201);
    }

    public function updateStatus(Request $request, Sugestao $sugestao)
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'diretor']), 403);

        $data = $request->validate([
            'status' => ['required', 'in:pendente,em_analise,aprovada,rejeitada,implementada'],
            'resposta_admin' => ['nullable', 'string', 'max:2000'],
        ]);

        $sugestao->update([
            'status' => $data['status'],
            'resposta_admin' => $data['resposta_admin'] ?? $sugestao->resposta_admin,
            'admin_respondeu_id' => $request->user()->id,
        ]);

        return response()->json($sugestao);
    }

    public function toggleVisibilidade(Request $request, Sugestao $sugestao)
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'diretor']), 403);

        $sugestao->update(['visivel' => ! $sugestao->visivel]);

        return response()->json($sugestao);
    }

    public function destroy(Request $request, Sugestao $sugestao)
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'diretor']), 403);

        $sugestao->delete();

        return response()->noContent();
    }
}
