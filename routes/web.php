<?php

use App\Http\Controllers\AnexoController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\ConversaController;
use App\Http\Controllers\DevolucaoController;
use App\Http\Controllers\DossieController;
use App\Http\Controllers\EstatisticaController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\PrioridadeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ─── PORTA DE ENTRADA ─────────────────────────────────────────────────────────
//
// Não existe página pública: quem chega vai para o login, e quem já está logado
// vai direto para a fila (o logo da navbar aponta para cá). Antes aqui morava a
// splash padrão do Laravel, que anunciava a versão do framework e do PHP para
// quem varre a internet atrás de versão vulnerável.

Route::get('/', fn() => redirect()->route(auth()->check() ? 'notas.index' : 'login'));

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

    // ── Chat interno (a barra lateral) ─────────────────────────────────────────
    //
    // Todo mundo conversa com todo mundo, inclusive o visitante: aqui não se
    // executa ação sobre nota nenhuma, é recado entre colegas. A autorização é
    // participar da conversa, e mora no controller.
    //
    // A ORDEM IMPORTA: as rotas fixas ('mensagens/…') vêm antes de '/{pessoa}',
    // senão a palavra "mensagens" seria lida como o nome de um usuário.
    Route::prefix('conversas')->name('conversas.')->group(function () {
        Route::get('/', [ConversaController::class, 'index'])->name('index');

        // O anexo. Sai só por aqui, com 'auth' na frente e conferência de
        // participação — o arquivo mora fora de public/, como o da nota.
        Route::get('/mensagens/{mensagem}/arquivo', [ConversaController::class, 'arquivo'])
            ->name('mensagens.arquivo');

        Route::post('/{conversa}/lida', [ConversaController::class, 'lida'])->name('lida');

        Route::get('/{pessoa}',  [ConversaController::class, 'mostrar'])->name('mostrar');
        Route::post('/{pessoa}', [ConversaController::class, 'enviar'])->name('enviar');
    });

    // ── Devoluções (o quadro entre pré-lote e recebimento) ─────────────────────
    //
    // Os dois setores abrem e os dois conferem: o aviso vai numa direção ou na
    // outra conforme quem descobriu o problema. Compras fica de fora — isso se
    // resolve entre quem está com a mercadoria e a nota na mão.
    Route::middleware('can:usar-devolucoes')->prefix('devolucoes')->name('devolucoes.')->group(function () {
        Route::post('/',                     [DevolucaoController::class, 'store'])->name('store');
        Route::post('/{devolucao}/anexos',   [DevolucaoController::class, 'anexar'])->name('anexar');
        Route::post('/{devolucao}/conferir', [DevolucaoController::class, 'conferir'])->name('conferir');
        Route::post('/{devolucao}/reabrir',  [DevolucaoController::class, 'reabrir'])->name('reabrir');
        Route::delete('/{devolucao}',        [DevolucaoController::class, 'destroy'])->name('destroy');

        // O arquivo mora fora de public/ e só sai por aqui, com sessão na frente
        Route::get('/{devolucao}/anexos/{anexo}',    [DevolucaoController::class, 'arquivo'])->name('arquivo');
        Route::delete('/{devolucao}/anexos/{anexo}', [DevolucaoController::class, 'removerAnexo'])->name('anexos.destroy');
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

        // Anexos: documento/foto da nota. Enviar e remover é do recebimento e do
        // pré-lote (Gate dentro do controller); VER é de qualquer conta logada —
        // compras precisa da foto da avaria para resolver o card.
        // O arquivo mora fora de public/ e só sai por aqui, com 'auth' na frente.
        Route::prefix('{nota}/anexos')->name('anexos.')->group(function () {
            Route::get('/',                  [AnexoController::class, 'index'])->name('index');
            Route::post('/',                 [AnexoController::class, 'store'])->name('store');
            Route::get('/{anexo}',           [AnexoController::class, 'download'])->name('download');
            Route::delete('/{anexo}',        [AnexoController::class, 'destroy'])->name('destroy');
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
    // Upsert em massa no cadastro que todas as notas referenciam: só admin.
    Route::post('/fornecedores/importar', [FornecedorController::class, 'importar'])
         ->middleware('can:importar-fornecedores')
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
