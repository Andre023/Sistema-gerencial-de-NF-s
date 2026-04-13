<?php

use App\Http\Controllers\CadastroController;
use App\Http\Controllers\EstatisticaController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequisicaoController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ─── PÚBLICO ──────────────────────────────────────────────────────────────────

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});

// ─── AUTENTICADO ──────────────────────────────────────────────────────────────

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard (redireciona para requisições)
    Route::get('/dashboard', fn() => redirect()->route('requisicoes.index'))->name('dashboard');

    // Perfil
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ── Requisições ────────────────────────────────────────────────────────────
    Route::prefix('requisicoes')->name('requisicoes.')->group(function () {
        Route::get('/',           [RequisicaoController::class, 'index'])->name('index');
        Route::post('/',          [RequisicaoController::class, 'store'])->name('store');
        Route::patch('/{requisicao}', [RequisicaoController::class, 'update'])->name('update');
        Route::delete('/{requisicao}', [RequisicaoController::class, 'destroy'])->name('destroy');
    });

    // ── Fornecedores ───────────────────────────────────────────────────────────
    Route::post('/fornecedores/importar', [FornecedorController::class, 'importar'])
         ->name('fornecedores.importar');

    // ── Cadastros ──────────────────────────────────────────────────────────────
    Route::prefix('cadastros')->name('cadastros.')->group(function () {
        Route::get('/',               [CadastroController::class, 'index'])->name('index');
        Route::post('/',              [CadastroController::class, 'store'])->name('store');
        Route::patch('/{cadastro}',   [CadastroController::class, 'update'])->name('update');
        Route::delete('/{cadastro}',  [CadastroController::class, 'destroy'])->name('destroy');
    });

    // ── Estatísticas ───────────────────────────────────────────────────────────
    Route::get('/estatisticas', [EstatisticaController::class, 'index'])->name('estatisticas.index');
});

require __DIR__ . '/auth.php';
