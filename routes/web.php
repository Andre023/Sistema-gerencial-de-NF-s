<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\DossieController;
use App\Http\Controllers\EstatisticaController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\PrioridadeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
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

    // Dashboard (redireciona para a fila de notas)
    Route::get('/dashboard', fn() => redirect()->route('notas.index'))->name('dashboard');

    // Perfil
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/profile/notificacoes', [ProfileController::class, 'notificacoes'])
         ->name('profile.notificacoes');
    Route::patch('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');

    // ── Sino ───────────────────────────────────────────────────────────────────
    Route::prefix('notificacoes')->name('notificacoes.')->group(function () {
        Route::post('/{notificacao}/abrir', [NotificacaoController::class, 'abrir'])->name('abrir');
        Route::post('/ler-todas',           [NotificacaoController::class, 'lerTodas'])->name('lerTodas');
    });

    // ── Notas (a fila do dia) ──────────────────────────────────────────────────
    Route::prefix('notas')->name('notas.')->group(function () {
        Route::get('/',              [NotaController::class, 'index'])->name('index');
        Route::post('/',             [NotaController::class, 'store'])->name('store');
        Route::patch('/{nota}',      [NotaController::class, 'update'])->name('update');
        Route::patch('/{nota}/liberada', [NotaController::class, 'editarLiberada'])->name('editar-liberada');
        Route::post('/{nota}/liberar', [NotaController::class, 'liberar'])->name('liberar');
        Route::post('/{nota}/devolver', [NotaController::class, 'devolver'])->name('devolver');
        Route::post('/{nota}/cancelar',    [NotaController::class, 'cancelar'])->name('cancelar');
        Route::post('/{nota}/descancelar', [NotaController::class, 'descancelar'])->name('descancelar');
        Route::post('/{nota}/visualizar', [NotaController::class, 'visualizar'])->name('visualizar');
        Route::delete('/{nota}',     [NotaController::class, 'destroy'])->name('destroy');

        // Cards de divergência: abrir (pré-lote) → corrigir (compras) → resolver (pré-lote)
        Route::prefix('{nota}/cards')->name('cards.')->group(function () {
            Route::post('/',                      [CardController::class, 'store'])->name('store');
            Route::patch('/{card}/corrigir',      [CardController::class, 'corrigir'])->name('corrigir');
            Route::patch('/{card}/resolver',      [CardController::class, 'resolver'])->name('resolver');
            Route::patch('/{card}/reabrir',       [CardController::class, 'reabrir'])->name('reabrir');
            Route::delete('/{card}',              [CardController::class, 'destroy'])->name('destroy');
        });

        // Comentários (JSON — o modal busca a thread sob demanda). Todos os papéis
        // comentam: é o canal de contexto entre recebimento, pré-lote e compras.
        Route::prefix('{nota}/comentarios')->name('comentarios.')->group(function () {
            Route::get('/',                [ComentarioController::class, 'index'])->name('index');
            Route::post('/',               [ComentarioController::class, 'store'])->name('store');
            Route::delete('/{comentario}', [ComentarioController::class, 'destroy'])->name('destroy');
        });
    });

    // ── Fornecedores ───────────────────────────────────────────────────────────
    Route::post('/fornecedores/importar', [FornecedorController::class, 'importar'])
         ->name('fornecedores.importar');

    // ── Estatísticas (só admin) ────────────────────────────────────────────────
    Route::get('/estatisticas', [EstatisticaController::class, 'index'])
        ->middleware('can:ver-estatisticas')
        ->name('estatisticas.index');

    // ── Dossiê do fornecedor ───────────────────────────────────────────────────
    Route::get('/dossie', [DossieController::class, 'index'])
        ->middleware('can:ver-dossie')
        ->name('dossie.index');

    // ── Prioridades (só admin) ─────────────────────────────────────────────────
    Route::middleware('can:gerenciar-prioridades')->prefix('prioridades')->name('prioridades.')->group(function () {
        Route::get('/',               [PrioridadeController::class, 'index'])->name('index');
        Route::patch('/{fornecedor}', [PrioridadeController::class, 'alternar'])->name('alternar');
    });

    // ── Usuários (só admin) ────────────────────────────────────────────────────
    Route::middleware('can:gerenciar-usuarios')->prefix('usuarios')->name('usuarios.')->group(function () {
        Route::get('/',              [UserController::class, 'index'])->name('index');
        Route::post('/',             [UserController::class, 'store'])->name('store');
        Route::patch('/{user}',      [UserController::class, 'update'])->name('update');
        Route::delete('/{user}',     [UserController::class, 'destroy'])->name('destroy');
    });
});

require __DIR__ . '/auth.php';
