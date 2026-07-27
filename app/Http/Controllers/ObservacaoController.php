<?php

namespace App\Http\Controllers;

use App\Models\Observacao;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Http\Request;

class ObservacaoController extends Controller
{
    public function __construct(private readonly DashboardScopeResolver $scopeResolver)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );
        $userIds = $this->scopeResolver->usuarioIds($user, $scope);

        return Observacao::query()
            ->with('user:id,name,display_name')
            ->whereIn('user_id', $userIds)
            ->orderByDesc('fixada')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Observacao $o) => [
                'id' => $o->id,
                'autor' => $o->user->display_name ?: $o->user->name,
                'cnpj' => $o->cnpj,
                'mensagem' => $o->mensagem,
                'fixada' => $o->fixada,
                'podeEditar' => $o->user_id === $user->id,
                'criadoEm' => $o->created_at->toIso8601String(),
            ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['vendedor', 'representante']), 403);

        $data = $request->validate([
            'cnpj' => ['required', 'string', 'max:18'],
            'mensagem' => ['required', 'string', 'max:2000'],
        ]);

        $observacao = Observacao::create([
            'user_id' => $user->id,
            'cnpj' => $data['cnpj'],
            'mensagem' => $data['mensagem'],
        ]);

        return response()->json($observacao, 201);
    }

    public function togglePin(Request $request, Observacao $observacao)
    {
        abort_unless($observacao->user_id === $request->user()->id, 403);

        $observacao->update(['fixada' => ! $observacao->fixada]);

        return response()->json($observacao);
    }
}
