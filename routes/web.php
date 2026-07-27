<?php

use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipeController;
use App\Http\Controllers\ObservacaoController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SugestaoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/sugestoes', [SugestaoController::class, 'index'])->name('sugestoes.index');
    Route::post('/sugestoes', [SugestaoController::class, 'store'])->name('sugestoes.store');
    Route::patch('/sugestoes/{sugestao}/status', [SugestaoController::class, 'updateStatus'])->name('sugestoes.status');
    Route::patch('/sugestoes/{sugestao}/visibilidade', [SugestaoController::class, 'toggleVisibilidade'])->name('sugestoes.visibilidade');
    Route::delete('/sugestoes/{sugestao}', [SugestaoController::class, 'destroy'])->name('sugestoes.destroy');

    Route::get('/pedidos-abertos', [PedidoController::class, 'index'])->name('pedidos.index');

    Route::get('/carteira', [CarteiraController::class, 'index'])->name('carteira.index');
    Route::patch('/carteira/{cliente}/ocultar', [CarteiraController::class, 'ocultar'])->name('carteira.ocultar');
    Route::post('/carteira/{cliente}/contatado', [CarteiraController::class, 'marcarContatado'])->name('carteira.contatado');
    Route::post('/carteira/{cliente}/motivo-inatividade', [CarteiraController::class, 'registrarMotivoInatividade'])->name('carteira.motivoInatividade');

    Route::get('/orcamentos', [OrcamentoController::class, 'index'])->name('orcamentos.index');
    Route::post('/orcamentos', [OrcamentoController::class, 'store'])->name('orcamentos.store');
    Route::patch('/orcamentos/{orcamento}', [OrcamentoController::class, 'update'])->name('orcamentos.update');
    Route::patch('/orcamentos/{orcamento}/aprovar', [OrcamentoController::class, 'aprovar'])->name('orcamentos.aprovar');
    Route::patch('/orcamentos/{orcamento}/rejeitar', [OrcamentoController::class, 'rejeitar'])->name('orcamentos.rejeitar');
    Route::delete('/orcamentos/{orcamento}', [OrcamentoController::class, 'destroy'])->name('orcamentos.destroy');
    Route::get('/orcamentos/{orcamento}/pdf', [OrcamentoController::class, 'pdf'])->name('orcamentos.pdf');

    Route::get('/observacoes', [ObservacaoController::class, 'index'])->name('observacoes.index');
    Route::post('/observacoes', [ObservacaoController::class, 'store'])->name('observacoes.store');
    Route::patch('/observacoes/{observacao}/fixar', [ObservacaoController::class, 'togglePin'])->name('observacoes.fixar');

    Route::get('/equipe', [EquipeController::class, 'index'])->name('equipe.index');
    Route::post('/equipe', [EquipeController::class, 'store'])->name('equipe.store');
    Route::patch('/equipe/supervisor-massa', [EquipeController::class, 'reatribuirSupervisorMassa'])->name('equipe.supervisorMassa');
    Route::patch('/equipe/{usuario}', [EquipeController::class, 'update'])->name('equipe.update');
    Route::patch('/equipe/{usuario}/senha', [EquipeController::class, 'atualizarSenha'])->name('equipe.senha');
    Route::patch('/equipe/{usuario}/status', [EquipeController::class, 'toggleStatus'])->name('equipe.status');
    Route::delete('/equipe/{usuario}', [EquipeController::class, 'destroy'])->name('equipe.destroy');
});

require __DIR__.'/auth.php';
