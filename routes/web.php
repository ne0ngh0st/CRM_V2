<?php

use App\Http\Controllers\CadastroController;
use App\Http\Controllers\CarteiraController;
use App\Http\Controllers\CatalogoFacaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipeController;
use App\Http\Controllers\EtiquetaMateriaPrimaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\ObservacaoController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SimulacaoController;
use App\Http\Controllers\SugestaoController;
use App\Http\Controllers\TabelaPrecoController;
use App\Http\Controllers\VisaoGestorController;
use Illuminate\Support\Facades\Route;

Route::get('/', InicioController::class)->name('inicio');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/foto', [ProfileController::class, 'updateFoto'])->name('profile.foto.update');
    Route::delete('/profile/foto', [ProfileController::class, 'destroyFoto'])->name('profile.foto.destroy');

    Route::get('/notificacoes', [NotificacaoController::class, 'index'])->name('notificacoes.index');
    Route::post('/notificacoes/marcar-todas', [NotificacaoController::class, 'marcarTodas'])->name('notificacoes.marcarTodas');
    Route::post('/notificacoes/{notificacao}/marcar-lida', [NotificacaoController::class, 'marcarLida'])->name('notificacoes.marcarLida');

    Route::get('/sugestoes', [SugestaoController::class, 'index'])->name('sugestoes.index');
    Route::post('/sugestoes', [SugestaoController::class, 'store'])->name('sugestoes.store');
    Route::patch('/sugestoes/{sugestao}/status', [SugestaoController::class, 'updateStatus'])->name('sugestoes.status');
    Route::patch('/sugestoes/{sugestao}/visibilidade', [SugestaoController::class, 'toggleVisibilidade'])->name('sugestoes.visibilidade');
    Route::delete('/sugestoes/{sugestao}', [SugestaoController::class, 'destroy'])->name('sugestoes.destroy');

    Route::get('/pedidos-abertos', [PedidoController::class, 'index'])->name('pedidos.index');
    Route::get('/pedidos-abertos/exportar', [PedidoController::class, 'exportarAbertos'])->name('pedidos.exportar');
    Route::get('/pedidos-emitidos', [PedidoController::class, 'emitidos'])->name('pedidos.emitidos');
    Route::get('/pedidos-emitidos/exportar', [PedidoController::class, 'exportarEmitidos'])->name('pedidos.exportarEmitidos');

    Route::get('/carteira', [CarteiraController::class, 'index'])->name('carteira.index');
    Route::get('/carteira/exportar', [CarteiraController::class, 'exportar'])->name('carteira.exportar');
    Route::get('/carteira/{cliente}/detalhes', [CarteiraController::class, 'detalhes'])->name('carteira.detalhes');
    Route::post('/carteira/{cliente}/motivo-inatividade', [CarteiraController::class, 'registrarMotivoInatividade'])->name('carteira.motivoInatividade');
    Route::post('/carteira/{cliente}/ligacao', [CarteiraController::class, 'registrarLigacao'])->name('carteira.ligacao');
    Route::post('/carteira/{cliente}/agendamento', [CarteiraController::class, 'registrarAgendamento'])->name('carteira.agendamento');
    Route::patch('/carteira/agendamentos/{agendamento}', [CarteiraController::class, 'atualizarAgendamento'])->name('carteira.agendamentoStatus');

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/exportar', [LeadController::class, 'exportar'])->name('leads.exportar');
    Route::post('/leads/{lead}/ligacao', [LeadController::class, 'registrarLigacao'])->name('leads.ligacao');
    Route::post('/leads/{lead}/agendamento', [LeadController::class, 'registrarAgendamento'])->name('leads.agendamento');
    Route::patch('/leads/agendamentos/{agendamento}', [LeadController::class, 'atualizarAgendamento'])->name('leads.agendamentoStatus');
    Route::delete('/leads/{lead}', [LeadController::class, 'excluir'])->name('leads.destroy');

    Route::get('/tabela-precos', [TabelaPrecoController::class, 'index'])->name('tabela-precos.index');
    Route::get('/tabela-precos/exportar', [TabelaPrecoController::class, 'exportar'])->name('tabela-precos.exportar');

    Route::get('/catalogo-facas', [CatalogoFacaController::class, 'index'])->name('catalogo-facas.index');
    // CRUD admin-only (checado no controller, como no EtiquetaMateriaPrimaController).
    Route::post('/catalogo-facas', [CatalogoFacaController::class, 'store'])->name('catalogo-facas.store');
    Route::post('/catalogo-facas/recursos/{recurso}/excluir', [CatalogoFacaController::class, 'destroyRecurso'])->name('catalogo-facas.recursos.destroy');
    Route::post('/catalogo-facas/{faca}/recursos', [CatalogoFacaController::class, 'storeRecurso'])->name('catalogo-facas.recursos.store');
    Route::patch('/catalogo-facas/{faca}', [CatalogoFacaController::class, 'update'])->name('catalogo-facas.update');
    Route::delete('/catalogo-facas/{faca}', [CatalogoFacaController::class, 'destroy'])->name('catalogo-facas.destroy');

    Route::get('/cadastros', [CadastroController::class, 'index'])->name('cadastros.index');
    Route::get('/cadastros/exportar', [CadastroController::class, 'exportar'])->name('cadastros.exportar');
    Route::post('/cadastros/bobinas', [CadastroController::class, 'storeBobina'])->name('cadastros.bobinas.store');
    Route::get('/cadastros/bobinas/{bobina}/pdf', [CadastroController::class, 'pdfBobina'])->name('cadastros.bobinas.pdf');
    Route::post('/cadastros/bobinas/{bobina}/enviar', [CadastroController::class, 'enviarBobina'])->name('cadastros.bobinas.enviar');
    Route::delete('/cadastros/bobinas/{bobina}', [CadastroController::class, 'destroyBobina'])->name('cadastros.bobinas.destroy');
    Route::post('/cadastros/etiquetas', [CadastroController::class, 'storeEtiqueta'])->name('cadastros.etiquetas.store');
    Route::post('/cadastros/etiquetas/{etiqueta}/enviar', [CadastroController::class, 'enviarEtiqueta'])->name('cadastros.etiquetas.enviar');
    Route::delete('/cadastros/etiquetas/{etiqueta}', [CadastroController::class, 'destroyEtiqueta'])->name('cadastros.etiquetas.destroy');
    Route::post('/cadastros/clientes', [CadastroController::class, 'storeCliente'])->name('cadastros.clientes.store');
    Route::delete('/cadastros/clientes/{cliente}', [CadastroController::class, 'destroyCliente'])->name('cadastros.clientes.destroy');
    Route::post('/cadastros/leads', [CadastroController::class, 'storeLead'])->name('cadastros.leads.store');
    Route::delete('/cadastros/leads/{lead}', [CadastroController::class, 'destroyLead'])->name('cadastros.leads.destroy');

    Route::get('/orcamentos', [OrcamentoController::class, 'index'])->name('orcamentos.index');
    Route::get('/orcamentos/exportar', [OrcamentoController::class, 'exportar'])->name('orcamentos.exportar');
    Route::get('/orcamentos/novo', [OrcamentoController::class, 'novo'])->name('orcamentos.novo');
    Route::get('/orcamentos/busca-clientes', [OrcamentoController::class, 'buscarClientes'])->name('orcamentos.buscaClientes');
    Route::get('/orcamentos/busca-produtos', [OrcamentoController::class, 'buscarProdutos'])->name('orcamentos.buscaProdutos');

    Route::get('/orcamentos/materia-prima', [EtiquetaMateriaPrimaController::class, 'index'])->name('etiquetas.materiaPrima.index');
    Route::post('/orcamentos/materia-prima', [EtiquetaMateriaPrimaController::class, 'store'])->name('etiquetas.materiaPrima.store');
    Route::patch('/orcamentos/materia-prima/{materiaPrima}', [EtiquetaMateriaPrimaController::class, 'update'])->name('etiquetas.materiaPrima.update');
    Route::delete('/orcamentos/materia-prima/{materiaPrima}', [EtiquetaMateriaPrimaController::class, 'destroy'])->name('etiquetas.materiaPrima.destroy');
    Route::post('/orcamentos/etiquetas/calcular', [EtiquetaMateriaPrimaController::class, 'calcular'])->name('etiquetas.calcular');

    Route::post('/orcamentos', [OrcamentoController::class, 'store'])->name('orcamentos.store');
    Route::get('/orcamentos/{orcamento}/editar', [OrcamentoController::class, 'editar'])->name('orcamentos.editar');
    Route::patch('/orcamentos/{orcamento}', [OrcamentoController::class, 'update'])->name('orcamentos.update');
    Route::patch('/orcamentos/{orcamento}/aprovar', [OrcamentoController::class, 'aprovar'])->name('orcamentos.aprovar');
    Route::patch('/orcamentos/{orcamento}/rejeitar', [OrcamentoController::class, 'rejeitar'])->name('orcamentos.rejeitar');
    Route::delete('/orcamentos/{orcamento}', [OrcamentoController::class, 'destroy'])->name('orcamentos.destroy');
    Route::get('/orcamentos/{orcamento}/pdf', [OrcamentoController::class, 'pdf'])->name('orcamentos.pdf');

    Route::get('/observacoes', [ObservacaoController::class, 'index'])->name('observacoes.index');
    Route::get('/observacoes/cliente/{cliente}', [ObservacaoController::class, 'porCliente'])->name('observacoes.porCliente');
    Route::get('/observacoes/lead/{lead}', [ObservacaoController::class, 'porLead'])->name('observacoes.porLead');
    Route::post('/observacoes', [ObservacaoController::class, 'store'])->name('observacoes.store');
    Route::patch('/observacoes/{observacao}/fixar', [ObservacaoController::class, 'togglePin'])->name('observacoes.fixar');

    Route::get('/equipe', [EquipeController::class, 'index'])->name('equipe.index');
    Route::get('/equipe/exportar', [EquipeController::class, 'exportar'])->name('equipe.exportar');
    Route::post('/equipe', [EquipeController::class, 'store'])->name('equipe.store');
    Route::patch('/equipe/supervisor-massa', [EquipeController::class, 'reatribuirSupervisorMassa'])->name('equipe.supervisorMassa');
    Route::patch('/equipe/{usuario}', [EquipeController::class, 'update'])->name('equipe.update');
    Route::patch('/equipe/{usuario}/senha', [EquipeController::class, 'atualizarSenha'])->name('equipe.senha');
    Route::patch('/equipe/{usuario}/status', [EquipeController::class, 'toggleStatus'])->name('equipe.status');
    Route::delete('/equipe/{usuario}', [EquipeController::class, 'destroy'])->name('equipe.destroy');

    // Simular usuário: iniciar é admin-only (checado no controller); encerrar precisa
    // valer pro usuário SIMULADO, que normalmente não é admin — a autorização de lá é
    // "existe simulação nesta sessão".
    // `encerrar` precisa vir ANTES de `{usuario}`, senão a rota literal é capturada pelo
    // parâmetro e o model binding devolve 404 procurando um usuário chamado "encerrar".
    Route::post('/simulacao/encerrar', [SimulacaoController::class, 'encerrar'])->name('simulacao.encerrar');
    Route::post('/simulacao/{usuario}', [SimulacaoController::class, 'iniciar'])->name('simulacao.iniciar');

    Route::get('/visao-gestor', [VisaoGestorController::class, 'index'])->name('visao-gestor.index');
    Route::get('/metas', [MetaController::class, 'index'])->name('metas.index');
    Route::get('/metas/exportar', [MetaController::class, 'exportar'])->name('metas.exportar');
    Route::patch('/metas', [MetaController::class, 'update'])->name('metas.update');
});

require __DIR__.'/auth.php';
