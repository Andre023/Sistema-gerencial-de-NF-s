<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // ─── Autorização por função ─────────────────────────────────────────────
        // As regras vivem no model User (fonte única, reaproveitada pelo frontend).
        Gate::define('lancar-nota',        fn(User $u) => $u->podeLancarNota());
        Gate::define('gerir-cards',        fn(User $u) => $u->podeGerirCards());
        Gate::define('corrigir-card',      fn(User $u) => $u->podeCorrigirCard());
        Gate::define('liberar-nota',       fn(User $u) => $u->podeLiberarNota());
        Gate::define('editar-notas',       fn(User $u) => $u->podeEditarNotas());
        Gate::define('gerenciar-notas',    fn(User $u) => $u->podeGerenciarNotas());
        Gate::define('devolver-nota',      fn(User $u) => $u->podeDevolverNota());
        Gate::define('excluir-nota-liberada', fn(User $u) => $u->podeExcluirNotaLiberada());
        Gate::define('ver-estatisticas',   fn(User $u) => $u->podeVerEstatisticas());
        Gate::define('gerenciar-usuarios', fn(User $u) => $u->podeGerenciarUsuarios());
        Gate::define('gerenciar-prioridades', fn(User $u) => $u->podeGerenciarPrioridades());
    }
}
