<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\FotoController;
use App\Http\Controllers\FuncionarioController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MinhaOsController;
use App\Http\Controllers\MovimentacaoVeiculoController;
use App\Http\Controllers\OrdemServicoController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\VeiculoController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

// ---------- Público ----------
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ---------- Precisa estar logado ----------
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/trocar-senha', [AuthController::class, 'trocarSenha']);

    // ---------- Logado e com a senha já trocada ----------
    Route::middleware(EnsurePasswordChanged::class)->group(function () {

        Route::put('/fcm-token', [AuthController::class, 'atualizarFcmToken']);

        // ---------- FUNCIONÁRIO: fluxo de trabalho ----------
        Route::get('/minhas-os', [MinhaOsController::class, 'index']);
        Route::get('/minhas-os/{os}', [MinhaOsController::class, 'show']);
        Route::post('/minhas-os/{os}/aceitar', [MinhaOsController::class, 'aceitar']);
        Route::post('/minhas-os/{os}/iniciar', [MinhaOsController::class, 'iniciar']);
        Route::post('/minhas-os/{os}/pausar', [MinhaOsController::class, 'pausar']);
        Route::post('/minhas-os/{os}/retomar', [MinhaOsController::class, 'retomar']);
        Route::post('/minhas-os/{os}/finalizar', [MinhaOsController::class, 'finalizar']);
        Route::get('/meu-apontamento', [MinhaOsController::class, 'atual']);
        Route::post('/minhas-os/{os}/materiais', [MaterialController::class, 'saidaFuncionario']);

        // ---------- COMPARTILHADO (admin e funcionário) ----------
        Route::get('/materiais', [MaterialController::class, 'index']);
        Route::post('/ordens-servico/{os}/fotos', [FotoController::class, 'store']);

        // ---------- ADMIN: gestão ----------
        Route::middleware(EnsureAdmin::class)->group(function () {

            Route::prefix('funcionarios')->group(function () {
                Route::get('/', [FuncionarioController::class, 'index']);
                Route::post('/', [FuncionarioController::class, 'store']);
                Route::get('/{funcionario}', [FuncionarioController::class, 'show']);
                Route::put('/{funcionario}', [FuncionarioController::class, 'update']);
                Route::patch('/{funcionario}/desativar', [FuncionarioController::class, 'desativar']);
                Route::patch('/{funcionario}/ativar', [FuncionarioController::class, 'ativar']);
                Route::post('/{funcionario}/redefinir-senha', [FuncionarioController::class, 'redefinirSenha']);
            });

            Route::get('/clientes', [ClienteController::class, 'index']);
            Route::post('/clientes', [ClienteController::class, 'store']);
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);

            Route::get('/veiculos', [VeiculoController::class, 'index']);
            Route::post('/veiculos', [VeiculoController::class, 'store']);
            Route::get('/veiculos/{veiculo}', [VeiculoController::class, 'show']);
            Route::put('/veiculos/{veiculo}', [VeiculoController::class, 'update']);

            // {os} = id da ordem de serviço
            Route::get('/ordens-servico', [OrdemServicoController::class, 'index']);
            Route::post('/ordens-servico', [OrdemServicoController::class, 'store']);
            Route::get('/ordens-servico/{os}', [OrdemServicoController::class, 'show']);
            Route::put('/ordens-servico/{os}', [OrdemServicoController::class, 'update']);
            Route::post('/ordens-servico/{os}/funcionarios', [OrdemServicoController::class, 'atribuirFuncionarios']);
            Route::post('/ordens-servico/{os}/agendamentos', [AgendaController::class, 'store']);

            Route::get('/agenda', [AgendaController::class, 'index']);
            Route::patch('/agendamentos/{agendamento}', [AgendaController::class, 'update']);

            // Entrada e saída de veículos
            Route::post('/ordens-servico/{os}/entrada-veiculo', [MovimentacaoVeiculoController::class, 'entrada']);
            Route::post('/ordens-servico/{os}/saida-veiculo', [MovimentacaoVeiculoController::class, 'saida']);
            Route::get('/ordens-servico/{os}/movimentacoes', [MovimentacaoVeiculoController::class, 'historico']);
            Route::get('/veiculos-status', [MovimentacaoVeiculoController::class, 'status']);
            Route::delete('/fotos/{foto}', [FotoController::class, 'destroy']);

            // Materiais
            Route::post('/materiais', [MaterialController::class, 'store']);
            Route::put('/materiais/{material}', [MaterialController::class, 'update']);
            Route::post('/materiais/{material}/entrada', [MaterialController::class, 'entrada']);
            Route::post('/materiais/{material}/saida', [MaterialController::class, 'saida']);
            Route::get('/materiais/{material}/movimentacoes', [MaterialController::class, 'movimentacoes']);
            Route::post('/movimentacoes-material/{movimentacao}/estornar', [MaterialController::class, 'estornar']);

            // Financeiro
            Route::get('/ordens-servico/{os}/pagamentos', [FinanceiroController::class, 'pagamentos']);
            Route::post('/ordens-servico/{os}/pagamentos', [FinanceiroController::class, 'registrarPagamento']);
            Route::get('/despesas', [FinanceiroController::class, 'despesas']);
            Route::post('/despesas', [FinanceiroController::class, 'registrarDespesa']);
            Route::put('/despesas/{despesa}', [FinanceiroController::class, 'atualizarDespesa']);
            Route::delete('/despesas/{despesa}', [FinanceiroController::class, 'excluirDespesa']);
            Route::post('/pagamentos/{pagamento}/estornar', [FinanceiroController::class, 'estornarPagamento']);

            // Leads
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::put('/leads/{lead}', [LeadController::class, 'update']);

            // Relatórios
            Route::prefix('relatorios')->group(function () {
                Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
                Route::get('/funcionarios', [RelatorioController::class, 'funcionarios']);
                Route::get('/funcionarios/{funcionario}', [RelatorioController::class, 'funcionarioDetalhe']);
                Route::get('/servicos-por-dia', [RelatorioController::class, 'servicosPorDia']);
                Route::get('/materiais', [RelatorioController::class, 'materiais']);
                Route::get('/financeiro', [FinanceiroController::class, 'resumo']);
                Route::get('/leads', [RelatorioController::class, 'leads']);
            });
        });
    });
});
