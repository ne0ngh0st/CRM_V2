<?php

namespace App\Http\Controllers;

use App\Models\Segmento;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Segmentos\CadastroDeSegmentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Criar, editar e excluir segmentos. Admin + diretor: nome e peso valem para a empresa
 * inteira. Supervisor continua só movendo pessoas no quadro ({@see EquipeController}).
 */
class SegmentoController extends Controller
{
    public function __construct(
        private readonly EquipeScopeResolver $scope,
        private readonly CadastroDeSegmentos $cadastro,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $request->merge([
            'codigo' => CadastroDeSegmentos::normalizarCodigo((string) $request->input('codigo')),
            'nome' => CadastroDeSegmentos::normalizarNome((string) $request->input('nome')),
        ]);

        $data = $request->validate([
            'codigo' => ['required', 'regex:/^[1-9]\d{0,5}$/', Rule::unique('segmentos', 'codigo')],
            'nome' => ['required', 'string', 'max:100', Rule::unique('segmentos', 'nome')],
            'peso' => ['required', 'numeric', 'min:0', 'max:20'],
        ], [
            'codigo.regex' => 'O código deve ser o número do segmento no TOTVS.',
            'codigo.unique' => 'Já existe um segmento com este código.',
            'nome.unique' => 'Já existe um segmento com este nome.',
        ]);

        $this->cadastro->criar($data['codigo'], $data['nome'], (float) $data['peso']);

        return back();
    }

    public function update(Request $request, Segmento $segmento): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $request->merge(['nome' => CadastroDeSegmentos::normalizarNome((string) $request->input('nome'))]);

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('segmentos', 'nome')->ignore($segmento->id)],
            'peso' => ['required', 'numeric', 'min:0', 'max:20'],
        ], [
            'nome.unique' => 'Já existe um segmento com este nome.',
        ]);

        $this->cadastro->atualizar($segmento, $data['nome'], (float) $data['peso']);

        return back();
    }

    public function destroy(Request $request, Segmento $segmento): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $this->cadastro->excluir($segmento);

        return back();
    }
}
