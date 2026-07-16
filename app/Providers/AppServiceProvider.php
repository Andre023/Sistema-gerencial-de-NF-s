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

        // ─── Autorização por papel ──────────────────────────────────────────────
        // As regras vivem no model User (fonte única, reaproveitada pelo frontend).
        Gate::define('gerenciar-registros', fn(User $u) => $u->podeGerenciarRegistros());
        Gate::define('ver-estatisticas',    fn(User $u) => $u->podeVerEstatisticas());
        Gate::define('gerenciar-usuarios',  fn(User $u) => $u->podeGerenciarUsuarios());
    }
}
