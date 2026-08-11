<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // ─── Senha ──────────────────────────────────────────────────────────────
        //
        // Password::defaults() sem configuração exige só 8 caracteres — "12345678"
        // passa. Como o login é a única porta do sistema e ele fica exposto na
        // internet, a senha fraca é o caminho mais curto para dentro; nenhuma das
        // outras travas ajuda depois que alguém entra com credencial válida.
        //
        // uncompromised() consulta a base pública de senhas vazadas mandando só o
        // começo do hash (a senha em si nunca sai daqui). Se o serviço estiver
        // fora do ar a regra passa — não trava o cadastro de usuário por isso.
        // Fora de produção fica leve, para os testes não dependerem de rede.
        Password::defaults(fn() => app()->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8));

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
        Gate::define('ver-dossie',         fn(User $u) => $u->podeVerDossie());
        Gate::define('gerenciar-usuarios', fn(User $u) => $u->podeGerenciarUsuarios());
        Gate::define('gerenciar-prioridades', fn(User $u) => $u->podeGerenciarPrioridades());
        Gate::define('importar-fornecedores', fn(User $u) => $u->podeImportarFornecedores());
        Gate::define('interagir',          fn(User $u) => $u->podeInteragir());
        Gate::define('cancelar-nota',      fn(User $u) => $u->podeCancelarNota());
        Gate::define('editar-observacao',  fn(User $u) => $u->podeEditarObservacao());
        Gate::define('editar-ceasa-liberada',      fn(User $u) => $u->podeEditarCeasaLiberada());
    }
}
